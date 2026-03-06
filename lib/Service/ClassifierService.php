<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Service;

use DateTime;
use CURLFile;
use OCA\PhotoDedup\AppInfo\Application;
use OCA\PhotoDedup\Db\FileClassification;
use OCA\PhotoDedup\Db\FileClassificationMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Classifies images into categories using heuristic analysis of EXIF metadata,
 * image dimensions, file naming patterns, and compression characteristics.
 *
 * Categories:
 *   - document  : Screenshots, scanned documents, receipts
 *   - meme      : Downloaded memes, social media images
 *   - nature    : Landscape and outdoor photography
 *   - family    : People, portraits, family photos
 *   - object    : Product photos, close-ups, everything else
 */
class ClassifierService
{
    private const SCOPE_ALL = 'all';
    private const SCOPE_PHOTOS = 'photos';

    private const CLEANUP_THROTTLE_SECONDS = 300;
    private const ML_MAX_CONSECUTIVE_FAILURES = 5;

    private bool $mlDisabledForRun = false;
    private int $mlConsecutiveFailures = 0;

    /** Valid classification categories. */
    public const CATEGORIES = ['document', 'meme', 'nature', 'family', 'object'];

    /** Common screen resolutions for screenshot detection. */
    private const SCREEN_DIMENSIONS = [
        [1920, 1080], [1080, 1920], [2560, 1440], [1440, 2560],
        [3840, 2160], [2160, 3840], [1366, 768], [768, 1366],
        [1280, 720], [720, 1280], [2048, 1536], [1536, 2048],
        [2732, 2048], [2048, 2732], [1125, 2436], [2436, 1125],
        [1170, 2532], [2532, 1170], [1284, 2778], [2778, 1284],
        [1290, 2796], [2796, 1290],
    ];

    /** Filename patterns suggesting documents/screenshots. */
    private const DOCUMENT_PATTERNS = [
        '/screenshot/i', '/bildschirmfoto/i', '/screen[\s_-]?shot/i',
        '/scan[\s_-]?\d/i', '/document/i', '/receipt/i', '/invoice/i',
        '/page[\s_-]?\d/i', '/pdf/i', '/print/i', '/statement/i',
    ];

    /** Filename patterns suggesting memes or downloaded social media. */
    private const MEME_PATTERNS = [
        '/^[a-f0-9]{8,}\.(?:jpg|jpeg|png|webp)$/i',
        '/meme/i', '/funny/i', '/lol/i',
        '/FB_IMG/i', '/instagram/i', '/reddit/i', '/twitter/i',
        '/received_\d+/i', '/signal-\d+/i', '/whatsapp/i',
        '/^IMG-\d{8}-WA\d+/i',
        '/download/i', '/saved/i',
    ];

    /** Filename patterns commonly associated with personal/family photos. */
    private const FAMILY_PATTERNS = [
        '/^IMG_\d{4,}/i',
        '/^PXL_\d{4,}/i',
        '/^DSC_\d{3,}/i',
        '/selfie/i',
        '/portrait/i',
        '/family/i',
        '/kids?/i',
        '/child/i',
        '/vacation/i',
        '/holiday/i',
    ];

    /** Common portrait focal lengths (mm, 35mm equivalent). */
    private const PORTRAIT_FOCAL_LENGTHS = [35, 50, 56, 85, 105, 135];

    /** MIME types currently supported by the external ML worker. */
    private const ML_SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly FileClassificationMapper $classificationMapper,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ── Public API ──────────────────────────────────────────────────

    /**
     * Classify all images for a user.
     *
     * @return array{total: int, classified: int, skipped: int, errors: int}
     */
    public function classifyUser(string $userId, bool $forceReclassify = false): array
    {
        $this->mlDisabledForRun = false;
        $this->mlConsecutiveFailures = 0;

        $this->setProgress($userId, 'classifying', 0, 0);

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
        } catch (NotFoundException $e) {
            $this->logger->warning('User folder not found, aborting classification', [
                'userId' => $userId,
                'exception' => $e,
            ]);
            $this->setProgress($userId, 'error', 0, 0);
            return ['total' => 0, 'classified' => 0, 'skipped' => 0, 'errors' => 0];
        }

        $this->cleanupStaleClassificationRecords($userId, $userFolder);

        $imageFiles = [];
        $this->collectImageFiles($userFolder, $imageFiles);
        $total = count($imageFiles);

        $this->setProgress($userId, 'classifying', $total, 0);

        $classified = 0;
        $skipped = 0;
        $errors = 0;
        $processed = 0;

        foreach ($imageFiles as $file) {
            try {
                $wasClassified = $this->classifyFile($userId, $file, $userFolder, $forceReclassify);
                if ($wasClassified) {
                    $classified++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->error('Failed to classify file', [
                    'userId' => $userId,
                    'filePath' => $file->getPath(),
                    'exception' => $e,
                ]);
            }

            $processed++;
            if ($processed % 50 === 0 || $processed === $total) {
                $this->setProgress($userId, 'classifying', $total, $processed);
            }
        }

        $this->setProgress($userId, 'completed', $total, $total);

        $this->logger->info('Classification completed', [
            'userId' => $userId,
            'total' => $total,
            'classified' => $classified,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);

        return compact('total', 'classified', 'skipped', 'errors');
    }

    /**
     * Classify a single file.
     *
     * @return bool True if the file was classified, false if skipped.
     */
    public function classifyFile(string $userId, File $file, Folder $userFolder, bool $force = false): bool
    {
        $fileId = $file->getId();
        if ($fileId === null || $file->getSize() === 0) {
            return false;
        }

        // Skip if already classified and not forced
        if (!$force) {
            try {
                $this->classificationMapper->findByFileId($userId, $fileId);
                return false; // Already classified
            } catch (DoesNotExistException) {
                // Not yet classified — proceed
            }
        }

        $filePath = $this->getUserRelativePath($userId, $file);
        $fileName = basename($filePath);
        $mimeType = $file->getMimeType();
        $fileSize = $file->getSize();

        // Extract EXIF data safely
        $exif = $this->extractExif($file);

        // Get image dimensions
        $dimensions = $this->getImageDimensions($file);
        $width = $dimensions['width'];
        $height = $dimensions['height'];

        // Try true image-content classification first (external ML worker)
        $mlResult = $this->classifyWithMlWorker($userId, $file, $mimeType, $fileName);

        if ($mlResult !== null) {
            $bestCategory = $mlResult['category'];
            $confidence = $mlResult['confidence'];
            $bestIndicators = $mlResult['indicators'];
        } else {
            // Fallback: run local heuristic rules
            $scores = $this->computeScores($fileName, $mimeType, $fileSize, $exif, $width, $height);

            // Pick the category with the highest score
            $bestCategory = 'object';
            $bestScore = 0.0;
            $bestIndicators = [];

            foreach ($scores as $category => $data) {
                if ($data['score'] > $bestScore) {
                    $bestScore = $data['score'];
                    $bestCategory = $category;
                    $bestIndicators = $data['indicators'];
                }
            }

            // Normalize confidence to 0–1 range (max raw score is ~1.0)
            $confidence = min(1.0, max(0.0, $bestScore));
            $bestIndicators[] = 'heuristic_fallback';
        }

        // Upsert classification record
        $this->upsertClassification(
            userId: $userId,
            fileId: $fileId,
            filePath: $filePath,
            fileSize: $fileSize,
            mimeType: $mimeType,
            category: $bestCategory,
            confidence: $confidence,
            indicators: $bestIndicators,
        );

        return true;
    }

    /**
     * Try classifying an image using an external ML worker.
     *
     * Expected endpoint behavior:
     *   - POST multipart/form-data with field "file"
     *   - Returns JSON: {"category":"nature","confidence":0.91,"model":"clip-vit-base-patch32"}
     *
     * Returns null when ML is disabled, unavailable, invalid, or low confidence.
     *
     * @return array{category: string, confidence: float, indicators: string[]}|null
     */
    private function classifyWithMlWorker(string $userId, File $file, string $mimeType, string $fileName): ?array
    {
        if (
            !$this->isMlEnabled()
            || $this->mlDisabledForRun
            || !in_array($mimeType, self::ML_SUPPORTED_MIME_TYPES, true)
        ) {
            return null;
        }

        if (!function_exists('curl_init')) {
            return null;
        }

        $endpoint = trim($this->config->getAppValue(
            Application::APP_ID,
            'ml_classifier_endpoint',
            'http://photodedup-ml-worker:8008/classify',
        ));
        if ($endpoint === '') {
            return null;
        }

        $retries = $this->clampInt(
            (int) $this->config->getAppValue(Application::APP_ID, 'ml_classifier_retries', '1'),
            0,
            3,
        );

        $timeout = $this->clampInt(
            (int) $this->config->getAppValue(Application::APP_ID, 'ml_classifier_timeout_seconds', '15'),
            2,
            120,
        );
        $maxFileBytes = $this->clampInt(
            (int) $this->config->getAppValue(Application::APP_ID, 'ml_classifier_max_file_bytes', '12582912'),
            512 * 1024,
            100 * 1024 * 1024,
        );
        $minConfidence = (float) $this->config->getAppValue(Application::APP_ID, 'ml_classifier_min_confidence', '0.45');
        $minConfidence = max(0.0, min(1.0, $minConfidence));

        $size = $file->getSize();
        if ($size <= 0 || $size > $maxFileBytes) {
            return null;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'pdd_ml_');
        if ($tempFile === false) {
            return null;
        }

        try {
            if (!$this->copyFileToLocalTemp($file, $tempFile, $maxFileBytes)) {
                return null;
            }

            $headers = ['Accept: application/json'];
            $token = trim($this->config->getAppValue(Application::APP_ID, 'ml_classifier_token', ''));
            if ($token !== '') {
                $headers[] = 'Authorization: Bearer ' . $token;
            }

            $payload = [
                'file' => new CURLFile($tempFile, $mimeType, $fileName),
                'candidate_labels' => implode(',', self::CATEGORIES),
            ];

            $responseBody = null;
            $statusCode = 0;
            $curlError = '';

            for ($attempt = 0; $attempt <= $retries; $attempt++) {
                $ch = curl_init($endpoint);
                if ($ch === false) {
                    break;
                }

                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => $timeout,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_POSTFIELDS => $payload,
                ]);

                $responseBody = curl_exec($ch);
                $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                $isSuccessful = $responseBody !== false && $statusCode >= 200 && $statusCode < 300;
                if ($isSuccessful) {
                    break;
                }

                if ($attempt < $retries) {
                    usleep((int) ((150000) * ($attempt + 1)));
                }
            }

            if ($responseBody === false || $statusCode < 200 || $statusCode >= 300) {
                // Client-side per-file errors (invalid/unsupported image payloads)
                // must not disable ML for the rest of the run.
                if (in_array($statusCode, [400, 413, 415, 422], true)) {
                    $this->logger->debug('ML classifier rejected file payload; using fallback for file', [
                        'userId' => $userId,
                        'fileId' => $file->getId(),
                        'statusCode' => $statusCode,
                    ]);
                    return null;
                }

                // Transport/server/auth failures count towards run-level breaker.
                $this->mlConsecutiveFailures++;

                if ($curlError !== '') {
                    $this->logger->debug('ML classifier request failed', [
                        'userId' => $userId,
                        'fileId' => $file->getId(),
                        'statusCode' => $statusCode,
                        'error' => $curlError,
                        'failureStreak' => $this->mlConsecutiveFailures,
                    ]);
                }

                if (
                    in_array($statusCode, [401, 403, 404], true)
                    || ($statusCode >= 500 && $statusCode <= 599)
                    || $responseBody === false
                ) {
                    if ($this->mlConsecutiveFailures >= self::ML_MAX_CONSECUTIVE_FAILURES) {
                        $this->mlDisabledForRun = true;
                        $this->logger->warning('Disabling ML worker for current classify run', [
                            'userId' => $userId,
                            'fileId' => $file->getId(),
                            'statusCode' => $statusCode,
                            'failureStreak' => $this->mlConsecutiveFailures,
                        ]);
                    }
                }

                return null;
            }

            $this->mlConsecutiveFailures = 0;

            $decoded = json_decode((string) $responseBody, true);
            if (!is_array($decoded)) {
                return null;
            }

            $category = (string) ($decoded['category'] ?? '');
            $confidence = (float) ($decoded['confidence'] ?? -1);
            $model = trim((string) ($decoded['model'] ?? 'external_ml'));
            $algorithmVersion = trim((string) ($decoded['algorithm_version'] ?? ''));

            if (!in_array($category, self::CATEGORIES, true) || $confidence < $minConfidence || $confidence > 1.0) {
                return null;
            }

            $indicators = [
                'ml_inference',
                'ml_model:' . $model,
            ];

            if ($algorithmVersion !== '') {
                $indicators[] = 'ml_algo:' . $algorithmVersion;
            }

            if (!empty($decoded['labels']) && is_array($decoded['labels'])) {
                foreach ($decoded['labels'] as $label) {
                    if (!is_array($label)) {
                        continue;
                    }
                    $name = trim((string) ($label['name'] ?? ''));
                    $score = isset($label['score']) ? (float) $label['score'] : null;
                    if ($name === '' || $score === null) {
                        continue;
                    }
                    $indicators[] = sprintf('ml_label:%s:%.3f', $name, max(0.0, min(1.0, $score)));
                }
            }

            return [
                'category' => $category,
                'confidence' => max(0.0, min(1.0, $confidence)),
                'indicators' => $indicators,
            ];
        } catch (\Throwable $e) {
            $this->logger->debug('ML classifier unavailable, using heuristic fallback', [
                'userId' => $userId,
                'fileId' => $file->getId(),
                'exception' => $e,
            ]);
            return null;
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Copy a Nextcloud file stream to a local temp file with a hard byte limit.
     */
    private function copyFileToLocalTemp(File $source, string $targetPath, int $maxBytes): bool
    {
        $input = $source->fopen('r');
        if (!is_resource($input)) {
            return false;
        }

        $output = fopen($targetPath, 'wb');
        if (!is_resource($output)) {
            fclose($input);
            return false;
        }

        $copied = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false) {
                    return false;
                }
                $len = strlen($chunk);
                if ($len === 0) {
                    continue;
                }
                $copied += $len;
                if ($copied > $maxBytes) {
                    return false;
                }
                if (fwrite($output, $chunk) === false) {
                    return false;
                }
            }
            return true;
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function isMlEnabled(): bool
    {
        return $this->config->getAppValue(Application::APP_ID, 'ml_classifier_enabled', 'false') === 'true';
    }

    private function clampInt(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    /**
     * Get classification progress for a user.
     *
     * @return array{status: string, total: int, processed: int}
     */
    public function getProgress(string $userId): array
    {
        $raw = $this->config->getUserValue($userId, Application::APP_ID, 'classify_progress', '');
        if ($raw === '') {
            return ['status' => 'idle', 'total' => 0, 'processed' => 0];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['status' => 'idle', 'total' => 0, 'processed' => 0];
        }

        return [
            'status' => (string) ($data['status'] ?? 'idle'),
            'total' => (int) ($data['total'] ?? 0),
            'processed' => (int) ($data['processed'] ?? 0),
        ];
    }

    /**
     * Get category counts for a user.
     *
     * @return array{categories: array<string, int>, total: int}
     */
    public function getCategoryCounts(string $userId, string $scope = self::SCOPE_ALL): array
    {
        $scope = $this->normalizeScope($scope);
        $this->cleanupStaleClassificationRecordsIfNeeded($userId);

        $counts = $this->classificationMapper->countByCategory($userId, $scope);
        $total = $this->classificationMapper->countForUser($userId, $scope);

        // Ensure all categories are represented
        foreach (self::CATEGORIES as $cat) {
            if (!isset($counts[$cat])) {
                $counts[$cat] = 0;
            }
        }

        return ['categories' => $counts, 'total' => $total];
    }

    private function cleanupStaleClassificationRecordsIfNeeded(string $userId): void
    {
        $progress = $this->getProgress($userId);
        if (($progress['status'] ?? 'idle') === 'classifying') {
            return;
        }

        $now = time();
        $lastCleanup = (int) $this->config->getUserValue($userId, Application::APP_ID, 'classify_cleanup_checked_at', '0');
        if (($now - $lastCleanup) < self::CLEANUP_THROTTLE_SECONDS) {
            return;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $this->cleanupStaleClassificationRecords($userId, $userFolder);
        } catch (\Throwable $e) {
            $this->logger->debug('Classification stale cleanup skipped', [
                'userId' => $userId,
                'exception' => $e,
            ]);
        } finally {
            $this->config->setUserValue($userId, Application::APP_ID, 'classify_cleanup_checked_at', (string) $now);
        }
    }

    private function cleanupStaleClassificationRecords(string $userId, Folder $userFolder): void
    {
        $batchSize = 1000;
        $lastSeenId = 0;
        $removed = 0;

        while (true) {
            $records = $this->classificationMapper->findForUserAfterId($userId, $lastSeenId, $batchSize);
            if ($records === []) {
                break;
            }

            foreach ($records as $record) {
                $lastSeenId = max($lastSeenId, $record->getId() ?? 0);
                $fileId = $record->getFileId();

                try {
                    $nodes = $userFolder->getById($fileId);
                } catch (\Throwable $e) {
                    $nodes = [];
                }

                if ($nodes === []) {
                    $this->classificationMapper->deleteByFileId($userId, $fileId);
                    $removed++;
                }
            }
        }

        if ($removed > 0) {
            $this->logger->info('Removed stale classification records', [
                'userId' => $userId,
                'removed' => $removed,
            ]);
        }
    }

    /**
     * Get files in a specific category (paginated).
     *
     * @return array{files: array, total: int}
     */
    public function getFilesByCategory(string $userId, string $category, int $limit = 50, int $offset = 0, string $scope = self::SCOPE_ALL): array
    {
        $scope = $this->normalizeScope($scope);

        if (!in_array($category, self::CATEGORIES, true)) {
            return ['files' => [], 'total' => 0];
        }

        $total = $this->classificationMapper->countByUserCategory($userId, $category, $scope);
        $entities = $this->classificationMapper->findByCategory($userId, $category, $limit, $offset, $scope);

        $files = array_map(
            static fn(FileClassification $fc): array => $fc->jsonSerialize(),
            $entities,
        );

        return ['files' => $files, 'total' => $total];
    }

    private function normalizeScope(string $scope): string
    {
        return $scope === self::SCOPE_PHOTOS ? self::SCOPE_PHOTOS : self::SCOPE_ALL;
    }

    /**
     * Move a file to a target folder.
     *
     * @return array{success: bool, message: string, newPath?: string}
     */
    public function moveFile(string $userId, int $fileId, string $targetFolder): array
    {
        // Sanitize target folder: must not escape user root
        $targetFolder = trim($targetFolder, '/');
        if ($targetFolder === '' || str_contains($targetFolder, '..')) {
            return ['success' => false, 'message' => 'Invalid target folder.'];
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $nodes = $userFolder->getById($fileId);

            if (empty($nodes)) {
                return ['success' => false, 'message' => 'File not found.'];
            }

            $node = $nodes[0];

            // Ensure target folder exists (create if needed)
            try {
                $target = $userFolder->get($targetFolder);
                if (!($target instanceof Folder)) {
                    return ['success' => false, 'message' => 'Target path is not a folder.'];
                }
            } catch (NotFoundException) {
                $target = $this->createFolderRecursive($userFolder, $targetFolder);
            }

            $fileName = $node->getName();
            $newPath = $targetFolder . '/' . $fileName;

            // Handle name conflicts by appending a suffix
            $finalName = $this->resolveNameConflict($target, $fileName);
            $node->move($target->getPath() . '/' . $finalName);

            // Update the classification record with the new path
            $newRelativePath = $targetFolder . '/' . $finalName;
            try {
                $record = $this->classificationMapper->findByFileId($userId, $fileId);
                $record->setFilePath($newRelativePath);
                $this->classificationMapper->update($record);
            } catch (DoesNotExistException) {
                // Classification record not found — acceptable
            }

            return [
                'success' => true,
                'message' => 'File moved successfully.',
                'newPath' => $newRelativePath,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to move file', [
                'userId' => $userId,
                'fileId' => $fileId,
                'targetFolder' => $targetFolder,
                'exception' => $e,
            ]);
            return ['success' => false, 'message' => 'Move failed: ' . $e->getMessage()];
        }
    }

    /**
     * Delete a classified file (move to trash).
     *
     * @return array{success: bool, message: string}
     */
    public function deleteFile(string $userId, int $fileId): array
    {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $nodes = $userFolder->getById($fileId);

            if (empty($nodes)) {
                $this->classificationMapper->deleteByFileId($userId, $fileId);
                return ['success' => true, 'message' => 'File already removed; index cleaned up.'];
            }

            $node = $nodes[0];
            $node->delete();

            $this->classificationMapper->deleteByFileId($userId, $fileId);

            return ['success' => true, 'message' => 'File deleted successfully.'];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete classified file', [
                'userId' => $userId,
                'fileId' => $fileId,
                'exception' => $e,
            ]);
            return ['success' => false, 'message' => 'Deletion failed: ' . $e->getMessage()];
        }
    }

    // ── Classification engine ───────────────────────────────────────

    /**
     * Compute raw scores for each category.
     *
     * @return array<string, array{score: float, indicators: string[]}>
     */
    private function computeScores(
        string $fileName,
        string $mimeType,
        int $fileSize,
        array $exif,
        int $width,
        int $height,
    ): array {
        $scores = [];
        foreach (self::CATEGORIES as $cat) {
            $scores[$cat] = ['score' => 0.0, 'indicators' => []];
        }

        $hasCamera = !empty($exif['Make']) || !empty($exif['Model']);
        $hasGps = !empty($exif['GPSLatitude']);
        $hasFlash = isset($exif['Flash']);
        $flashFired = $hasFlash && ((int) ($exif['Flash'] ?? 0) & 1) === 1;
        $focalLength = $this->parseFocalLength($exif);
        $aspectRatio = $height > 0 ? $width / $height : 1.0;
        $megapixels = ($width * $height) / 1_000_000;
        $bytesPerPixel = ($width > 0 && $height > 0) ? $fileSize / ($width * $height) : 0;
        $isPng = $mimeType === 'image/png';

        // ─── Document / Screenshot scoring ──────────────────────
        $this->scoreDocument(
            $scores, $fileName, $mimeType, $fileSize, $isPng,
            $hasCamera, $hasGps, $width, $height, $bytesPerPixel,
        );

        // ─── Meme scoring ───────────────────────────────────────
        $this->scoreMeme(
            $scores, $fileName, $mimeType, $fileSize,
            $hasCamera, $hasGps, $width, $height, $bytesPerPixel, $megapixels,
        );

        // ─── Nature / Landscape scoring ─────────────────────────
        $this->scoreNature(
            $scores, $hasCamera, $hasGps, $flashFired,
            $aspectRatio, $megapixels, $fileSize, $focalLength,
        );

        // ─── Family / People scoring ────────────────────────────
        $this->scoreFamily(
            $scores, $exif, $hasCamera, $hasGps, $flashFired,
            $aspectRatio, $megapixels, $fileSize, $focalLength, $fileName,
        );

        // ─── Object (default) scoring ───────────────────────────
        $this->scoreObject($scores, $hasCamera, $megapixels, $width, $height);

        $this->applyScoreCalibrations(
            $scores,
            $mimeType,
            $fileName,
            $hasCamera,
            $hasGps,
            $flashFired,
            $aspectRatio,
            $focalLength,
            $megapixels,
        );

        return $scores;
    }

    private function scoreDocument(
        array &$scores,
        string $fileName,
        string $mimeType,
        int $fileSize,
        bool $isPng,
        bool $hasCamera,
        bool $hasGps,
        int $width,
        int $height,
        float $bytesPerPixel,
    ): void {
        $hasDocumentFilename = $this->matchesPatterns($fileName, self::DOCUMENT_PATTERNS);

        // PNG without camera data strongly suggests screenshot
        if ($isPng && !$hasCamera) {
            $scores['document']['score'] += 0.18;
            $scores['document']['indicators'][] = 'png_no_camera';
        }

        // Matches common screen resolutions
        if ($this->isScreenResolution($width, $height) && !$hasCamera) {
            $scores['document']['score'] += 0.15;
            $scores['document']['indicators'][] = 'screen_resolution';
        }

        // Filename matches document patterns
        if ($hasDocumentFilename) {
            $scores['document']['score'] += 0.45;
            $scores['document']['indicators'][] = 'document_filename';
        }

        // No GPS — documents typically don't have GPS
        if (!$hasGps && !$hasCamera) {
            $scores['document']['score'] += 0.10;
            $scores['document']['indicators'][] = 'no_metadata';
        }

        // Low bytes-per-pixel indicates screenshot-like content (solid colors, text)
        if ($isPng && $bytesPerPixel > 0 && $bytesPerPixel < 1.5) {
            $scores['document']['score'] += 0.08;
            $scores['document']['indicators'][] = 'low_compression_ratio';
        }

        if ($hasCamera && !$hasDocumentFilename) {
            $scores['document']['score'] -= 0.25;
            $scores['document']['indicators'][] = 'camera_penalty';
        }

        if ($hasGps) {
            $scores['document']['score'] -= 0.10;
            $scores['document']['indicators'][] = 'gps_penalty';
        }

        if ($mimeType === 'image/jpeg' && !$hasDocumentFilename) {
            $scores['document']['score'] -= 0.08;
            $scores['document']['indicators'][] = 'jpeg_photo_bias_penalty';
        }
    }

    private function scoreMeme(
        array &$scores,
        string $fileName,
        string $mimeType,
        int $fileSize,
        bool $hasCamera,
        bool $hasGps,
        int $width,
        int $height,
        float $bytesPerPixel,
        float $megapixels,
    ): void {
        // No camera metadata — downloaded from internet
        if (!$hasCamera && !$hasGps) {
            $scores['meme']['score'] += 0.15;
            $scores['meme']['indicators'][] = 'no_camera_metadata';
        }

        // Small to medium file size (memes are typically compressed)
        if ($fileSize > 0 && $fileSize < 500_000) {
            $scores['meme']['score'] += 0.15;
            $scores['meme']['indicators'][] = 'small_file_size';
        }

        // Low resolution (memes are often low quality)
        if ($megapixels > 0 && $megapixels < 2.0) {
            $scores['meme']['score'] += 0.10;
            $scores['meme']['indicators'][] = 'low_resolution';
        }

        // JPEG with high compression (low bytes per pixel)
        if ($mimeType === 'image/jpeg' && $bytesPerPixel > 0 && $bytesPerPixel < 0.5) {
            $scores['meme']['score'] += 0.15;
            $scores['meme']['indicators'][] = 'high_jpeg_compression';
        }

        // Filename matches meme/social media patterns
        if ($this->matchesPatterns($fileName, self::MEME_PATTERNS)) {
            $scores['meme']['score'] += 0.30;
            $scores['meme']['indicators'][] = 'meme_filename';
        }

        // Aspect ratio close to 1:1 (square memes) or very wide (captioned)
        $aspectRatio = $height > 0 ? $width / $height : 1.0;
        if ($aspectRatio > 0.8 && $aspectRatio < 1.25 && $megapixels < 3) {
            $scores['meme']['score'] += 0.10;
            $scores['meme']['indicators'][] = 'square_aspect';
        }
    }

    private function scoreNature(
        array &$scores,
        bool $hasCamera,
        bool $hasGps,
        bool $flashFired,
        float $aspectRatio,
        float $megapixels,
        int $fileSize,
        ?float $focalLength,
    ): void {
        // GPS data strongly indicates a real photo taken outdoors
        if ($hasGps) {
            $scores['nature']['score'] += 0.12;
            $scores['nature']['indicators'][] = 'has_gps';
        }

        // Camera present
        if ($hasCamera) {
            $scores['nature']['score'] += 0.06;
            $scores['nature']['indicators'][] = 'has_camera';
        }

        // No flash — outdoor photos typically don't use flash
        if ($hasCamera && !$flashFired) {
            $scores['nature']['score'] += 0.08;
            $scores['nature']['indicators'][] = 'no_flash';
        }

        // Landscape orientation (wider than tall)
        if ($aspectRatio > 1.3) {
            $scores['nature']['score'] += 0.15;
            $scores['nature']['indicators'][] = 'landscape_orientation';
        }

        // Higher resolution (DSLR/mirrorless cameras)
        if ($megapixels >= 8) {
            $scores['nature']['score'] += 0.08;
            $scores['nature']['indicators'][] = 'high_resolution';
        }

        // Wide-angle focal length (landscape photography)
        if ($focalLength !== null && $focalLength <= 35) {
            $scores['nature']['score'] += 0.14;
            $scores['nature']['indicators'][] = 'wide_angle';
        }

        // Larger file size (less compressed, high quality)
        if ($fileSize > 3_000_000) {
            $scores['nature']['score'] += 0.05;
            $scores['nature']['indicators'][] = 'large_file';
        }

        if ($flashFired) {
            $scores['nature']['score'] -= 0.18;
            $scores['nature']['indicators'][] = 'flash_penalty';
        }

        if ($aspectRatio < 1.0) {
            $scores['nature']['score'] -= 0.12;
            $scores['nature']['indicators'][] = 'portrait_penalty';
        }

        if ($focalLength !== null && $focalLength >= 60) {
            $scores['nature']['score'] -= 0.08;
            $scores['nature']['indicators'][] = 'telephoto_penalty';
        }
    }

    private function scoreFamily(
        array &$scores,
        array $exif,
        bool $hasCamera,
        bool $hasGps,
        bool $flashFired,
        float $aspectRatio,
        float $megapixels,
        int $fileSize,
        ?float $focalLength,
        string $fileName,
    ): void {
        // Camera present — real photos
        if ($hasCamera) {
            $scores['family']['score'] += 0.10;
            $scores['family']['indicators'][] = 'has_camera';
        }

        // Portrait orientation or close to square
        if ($aspectRatio < 0.85) {
            $scores['family']['score'] += 0.15;
            $scores['family']['indicators'][] = 'portrait_orientation';
        }

        if ($aspectRatio >= 0.85 && $aspectRatio <= 1.45) {
            $scores['family']['score'] += 0.08;
            $scores['family']['indicators'][] = 'human_photo_aspect';
        }

        // Flash fired — common indoors with people
        if ($flashFired) {
            $scores['family']['score'] += 0.18;
            $scores['family']['indicators'][] = 'flash_used';
        }

        // Portrait focal lengths (35mm–135mm range)
        if ($focalLength !== null) {
            foreach (self::PORTRAIT_FOCAL_LENGTHS as $pfl) {
                if (abs($focalLength - $pfl) < 5) {
                    $scores['family']['score'] += 0.18;
                    $scores['family']['indicators'][] = 'portrait_focal_length';
                    break;
                }
            }
        }

        // Has date information (organized personal photos usually do)
        if (!empty($exif['DateTimeOriginal'])) {
            $scores['family']['score'] += 0.10;
            $scores['family']['indicators'][] = 'has_date';
        }

        if ($this->matchesPatterns($fileName, self::FAMILY_PATTERNS)) {
            $scores['family']['score'] += 0.20;
            $scores['family']['indicators'][] = 'family_filename';
        }

        // Higher quality file
        if ($megapixels >= 5 && $fileSize > 1_000_000) {
            $scores['family']['score'] += 0.10;
            $scores['family']['indicators'][] = 'high_quality';
        }

        // GPS with camera — could be travel/family photos
        if ($hasGps && $hasCamera) {
            $scores['family']['score'] += 0.05;
            $scores['family']['indicators'][] = 'gps_with_camera';
        }

        if (!$hasCamera && $megapixels >= 2.0 && $aspectRatio >= 0.75 && $aspectRatio <= 1.5) {
            $scores['family']['score'] += 0.08;
            $scores['family']['indicators'][] = 'phone_export_people_bias';
        }
    }

    /**
     * Calibrate heuristic scores to reduce obvious false positives.
     *
     * @param array<string, array{score: float, indicators: string[]}> $scores
     */
    private function applyScoreCalibrations(
        array &$scores,
        string $mimeType,
        string $fileName,
        bool $hasCamera,
        bool $hasGps,
        bool $flashFired,
        float $aspectRatio,
        ?float $focalLength,
        float $megapixels,
    ): void {
        $hasDocumentFilename = $this->matchesPatterns($fileName, self::DOCUMENT_PATTERNS);

        if (!$hasDocumentFilename && $scores['document']['score'] > 0.45) {
            $scores['document']['score'] -= 0.18;
            $scores['document']['indicators'][] = 'doc_without_filename_penalty';
        }

        if ($mimeType === 'image/jpeg' && !$hasDocumentFilename && $megapixels >= 1.5) {
            $scores['document']['score'] -= 0.10;
            $scores['document']['indicators'][] = 'jpeg_document_penalty';
        }

        if ($flashFired || ($focalLength !== null && $focalLength >= 50) || $aspectRatio < 1.15) {
            $scores['nature']['score'] -= 0.12;
            $scores['nature']['indicators'][] = 'nature_context_penalty';
        }

        if ($hasCamera && !$hasGps && $aspectRatio <= 1.45) {
            $scores['family']['score'] += 0.07;
            $scores['family']['indicators'][] = 'family_indoor_camera_bias';
        }

        if ($hasCamera && $hasGps && $scores['nature']['score'] > 0.35 && $scores['family']['score'] > 0.30) {
            $scores['family']['score'] += 0.05;
            $scores['family']['indicators'][] = 'family_close_competitor_boost';
        }

        foreach (self::CATEGORIES as $category) {
            $scores[$category]['score'] = max(0.0, min(1.0, $scores[$category]['score']));
        }
    }

    private function scoreObject(
        array &$scores,
        bool $hasCamera,
        float $megapixels,
        int $width,
        int $height,
    ): void {
        // Base score — object is the default fallback
        $scores['object']['score'] += 0.05;
        $scores['object']['indicators'][] = 'default_category';

        // Square aspect (product photos)
        $aspectRatio = $height > 0 ? $width / $height : 1.0;
        if ($aspectRatio > 0.9 && $aspectRatio < 1.1 && $hasCamera) {
            $scores['object']['score'] += 0.15;
            $scores['object']['indicators'][] = 'square_with_camera';
        }

        // Medium resolution
        if ($megapixels >= 2 && $megapixels < 8) {
            $scores['object']['score'] += 0.05;
            $scores['object']['indicators'][] = 'medium_resolution';
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * Extract EXIF data from a file safely.
     */
    private function extractExif(File $file): array
    {
        $mime = $file->getMimeType();

        // exif_read_data only works with JPEG and TIFF
        if ($mime !== 'image/jpeg' && $mime !== 'image/tiff') {
            return [];
        }

        try {
            // Write to a temporary file for exif_read_data (needs a file path)
            $tempFile = tempnam(sys_get_temp_dir(), 'pdd_exif_');
            if ($tempFile === false) {
                return [];
            }

            try {
                $handle = $file->fopen('r');
                if (!is_resource($handle)) {
                    return [];
                }

                // Read only the first 64KB — EXIF is in the header
                $headerData = fread($handle, 65536);
                fclose($handle);

                if ($headerData === false || $headerData === '') {
                    return [];
                }

                file_put_contents($tempFile, $headerData);

                // Suppress warnings — EXIF data can be malformed
                $exif = @exif_read_data($tempFile, 'ANY_TAG', true);

                return is_array($exif) ? $this->flattenExif($exif) : [];
            } finally {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('EXIF extraction failed', [
                'file' => $file->getPath(),
                'exception' => $e,
            ]);
            return [];
        }
    }

    /**
     * Flatten sectioned EXIF array into a single-level array.
     */
    private function flattenExif(array $exif): array
    {
        $flat = [];
        foreach ($exif as $section => $data) {
            if (is_array($data)) {
                foreach ($data as $key => $value) {
                    $flat[$key] = $value;
                }
            } else {
                $flat[$section] = $data;
            }
        }
        return $flat;
    }

    /**
     * Get image dimensions without loading the full image into memory.
     *
     * @return array{width: int, height: int}
     */
    private function getImageDimensions(File $file): array
    {
        try {
            $handle = $file->fopen('r');
            if (!is_resource($handle)) {
                return ['width' => 0, 'height' => 0];
            }

            // Read enough header data for getimagesizefromstring
            $headerData = fread($handle, 65536);
            fclose($handle);

            if ($headerData === false || $headerData === '') {
                return ['width' => 0, 'height' => 0];
            }

            $size = @getimagesizefromstring($headerData);
            if ($size === false) {
                return ['width' => 0, 'height' => 0];
            }

            return ['width' => (int) $size[0], 'height' => (int) $size[1]];
        } catch (\Throwable $e) {
            return ['width' => 0, 'height' => 0];
        }
    }

    /**
     * Parse focal length from EXIF data.
     */
    private function parseFocalLength(array $exif): ?float
    {
        // Try FocalLengthIn35mmFilm first (normalized)
        if (!empty($exif['FocalLengthIn35mmFilm'])) {
            return (float) $exif['FocalLengthIn35mmFilm'];
        }

        // Try FocalLength (may be a fraction string like "50/1")
        if (!empty($exif['FocalLength'])) {
            $val = $exif['FocalLength'];
            if (is_string($val) && str_contains($val, '/')) {
                $parts = explode('/', $val);
                $num = (float) ($parts[0] ?? 0);
                $den = (float) ($parts[1] ?? 1);
                return $den > 0 ? $num / $den : null;
            }
            return (float) $val;
        }

        return null;
    }

    /**
     * Check if dimensions match common screen resolutions.
     */
    private function isScreenResolution(int $width, int $height): bool
    {
        if ($width === 0 || $height === 0) {
            return false;
        }

        foreach (self::SCREEN_DIMENSIONS as [$sw, $sh]) {
            // Allow ±5% tolerance for scaled screenshots
            if (abs($width - $sw) < $sw * 0.05 && abs($height - $sh) < $sh * 0.05) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a filename matches any pattern in a list.
     */
    private function matchesPatterns(string $fileName, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $fileName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Recursively collect image files.
     *
     * @param File[] &$result
     */
    private function collectImageFiles(Folder $folder, array &$result): void
    {
        try {
            $nodes = $folder->getDirectoryListing();
        } catch (\Throwable $e) {
            return;
        }

        foreach ($nodes as $node) {
            if ($node instanceof Folder) {
                $name = $node->getName();
                if ($name === '.thumbnails' || $name === '.versions' || $name === '.trash') {
                    continue;
                }
                $this->collectImageFiles($node, $result);
            } elseif ($node instanceof File) {
                $mime = $node->getMimeType();
                if (in_array($mime, Application::SUPPORTED_MIME_TYPES, true)) {
                    $result[] = $node;
                }
            }
        }
    }

    /**
     * Get file path relative to user's root folder.
     */
    private function getUserRelativePath(string $userId, File $file): string
    {
        $prefix = "/{$userId}/files/";
        $fullPath = $file->getPath();

        if (str_starts_with($fullPath, $prefix)) {
            return substr($fullPath, strlen($prefix));
        }

        return $file->getInternalPath();
    }

    /**
     * Store classification progress in user config.
     */
    private function setProgress(string $userId, string $status, int $total, int $processed): void
    {
        $data = json_encode([
            'status' => $status,
            'total' => $total,
            'processed' => $processed,
            'updated_at' => (new DateTime())->format(\DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR);

        $this->config->setUserValue($userId, Application::APP_ID, 'classify_progress', $data);
    }

    /**
     * Upsert a classification record.
     */
    private function upsertClassification(
        string $userId,
        int $fileId,
        string $filePath,
        int $fileSize,
        string $mimeType,
        string $category,
        float $confidence,
        array $indicators,
    ): void {
        $indicatorsJson = json_encode($indicators, JSON_THROW_ON_ERROR);

        try {
            $existing = $this->classificationMapper->findByFileId($userId, $fileId);
            $existing->setFilePath($filePath);
            $existing->setFileSize($fileSize);
            $existing->setMimeType($mimeType);
            $existing->setCategory($category);
            $existing->setConfidence($confidence);
            $existing->setIndicators($indicatorsJson);
            $existing->setClassifiedAt(new DateTime());

            $this->classificationMapper->update($existing);
        } catch (DoesNotExistException) {
            $entity = new FileClassification();
            $entity->setUserId($userId);
            $entity->setFileId($fileId);
            $entity->setFilePath($filePath);
            $entity->setFileSize($fileSize);
            $entity->setMimeType($mimeType);
            $entity->setCategory($category);
            $entity->setConfidence($confidence);
            $entity->setIndicators($indicatorsJson);
            $entity->setClassifiedAt(new DateTime());

            try {
                $this->classificationMapper->insert($entity);
            } catch (\OC\DB\Exceptions\DbalException $e) {
                if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    // Race condition — retry as update
                    $retry = $this->classificationMapper->findByFileId($userId, $fileId);
                    $retry->setFilePath($filePath);
                    $retry->setFileSize($fileSize);
                    $retry->setMimeType($mimeType);
                    $retry->setCategory($category);
                    $retry->setConfidence($confidence);
                    $retry->setIndicators($indicatorsJson);
                    $retry->setClassifiedAt(new DateTime());
                    $this->classificationMapper->update($retry);
                } else {
                    throw $e;
                }
            }
        }
    }

    /**
     * Create folders recursively.
     */
    private function createFolderRecursive(Folder $root, string $path): Folder
    {
        $parts = explode('/', trim($path, '/'));
        $current = $root;

        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                continue;
            }
            try {
                $child = $current->get($part);
                if ($child instanceof Folder) {
                    $current = $child;
                } else {
                    throw new \RuntimeException("Path component '{$part}' is not a folder.");
                }
            } catch (NotFoundException) {
                $current = $current->newFolder($part);
            }
        }

        return $current;
    }

    /**
     * Resolve filename conflicts by appending a numeric suffix.
     */
    private function resolveNameConflict(Folder $folder, string $fileName): string
    {
        try {
            $folder->get($fileName);
        } catch (NotFoundException) {
            return $fileName;
        }

        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        $base = pathinfo($fileName, PATHINFO_FILENAME);

        for ($i = 1; $i < 10000; $i++) {
            $candidate = $ext !== '' ? "{$base}_{$i}.{$ext}" : "{$base}_{$i}";
            try {
                $folder->get($candidate);
            } catch (NotFoundException) {
                return $candidate;
            }
        }

        // Fallback: use timestamp
        $ts = time();
        return $ext !== '' ? "{$base}_{$ts}.{$ext}" : "{$base}_{$ts}";
    }
}
