<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Service;

use DateTime;
use OCA\PhotoDedup\AppInfo\Application;
use OCA\PhotoDedup\Db\FileFace;
use OCA\PhotoDedup\Db\FileFaceMapper;
use OCA\PhotoDedup\Db\FileLocation;
use OCA\PhotoDedup\Db\FileLocationMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class PeopleLocationService
{
    private const SCOPE_ALL = 'all';
    private const SCOPE_PHOTOS = 'photos';
    private const FACE_CLUSTER_DISTANCE = 8;
    private const MAX_CLUSTER_SIGNATURES = 24;
    private const FACE_SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/bmp',
        'image/tiff',
    ];
    private const LOCATION_DEFAULT_EXIF_READ_BYTES = 2097152;

    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly FileFaceMapper $fileFaceMapper,
        private readonly FileLocationMapper $fileLocationMapper,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Build people clusters from the cached DB table (fast read, no ML I/O).
     *
     * Face signatures stored in pdd_file_faces are clustered in memory using
     * hex hamming distance.  No ML worker calls happen at read time.
     *
     * @return array{clusters: array<int, array>, total_clusters: int, total_face_images: int}
     */
    public function getPeopleClusters(string $userId, string $scope = self::SCOPE_ALL): array
    {
        $scope = $this->normalizeScope($scope);

        $faceRecords = $this->fileFaceMapper->findWithFace($userId, $scope);

        $clusters = [];
        $faceImages = 0;

        foreach ($faceRecords as $record) {
            $signature = $record->getFaceSignature();
            if ($signature === null || trim($signature) === '') {
                continue;
            }

            $faceImages++;

            $entry = [
                'fileId' => $record->getFileId(),
                'filePath' => $record->getFilePath(),
                'mimeType' => $record->getMimeType(),
                'fileSize' => $record->getFileSize(),
                'faceConfidence' => $record->getFaceConfidence() !== null ? (float) $record->getFaceConfidence() : 0.0,
            ];

            $clusterIndex = $this->findClusterIndex($clusters, $signature);
            if ($clusterIndex === -1) {
                $clusters[] = [
                    'signature' => $signature,
                    'signatures' => [$signature],
                    'files' => [$entry],
                ];
            } else {
                $clusters[$clusterIndex]['files'][] = $entry;
                if (!isset($clusters[$clusterIndex]['signatures']) || !is_array($clusters[$clusterIndex]['signatures'])) {
                    $clusters[$clusterIndex]['signatures'] = [(string) $clusters[$clusterIndex]['signature']];
                }
                if (count($clusters[$clusterIndex]['signatures']) < self::MAX_CLUSTER_SIGNATURES && !in_array($signature, $clusters[$clusterIndex]['signatures'], true)) {
                    $clusters[$clusterIndex]['signatures'][] = $signature;
                }
            }
        }

        $normalized = [];
        $index = 1;
        foreach ($clusters as $cluster) {
            usort($cluster['files'], static fn(array $a, array $b): int => $b['fileSize'] <=> $a['fileSize']);
            $normalized[] = [
                'id' => 'person-' . $index,
                'name' => 'Person ' . $index,
                'count' => count($cluster['files']),
                'files' => $cluster['files'],
            ];
            $index++;
        }

        usort($normalized, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'clusters' => $normalized,
            'total_clusters' => count($normalized),
            'total_face_images' => $faceImages,
        ];
    }

    /**
     * Scan the user's image files for face signatures and cache results in DB.
     *
     * Only new/changed files are processed unless $force is true.
     * Files without detected faces are also recorded (has_face = false)
     * so they are not re-scanned on subsequent runs.
     *
     * @return array{total: int, scanned: int, skipped: int, with_face: int, errors: int}
     */
    public function scanPeopleData(string $userId, bool $force = false): array
    {
        $this->setPeopleProgress($userId, 'scanning', 0, 0);

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
        } catch (NotFoundException) {
            $this->setPeopleProgress($userId, 'error', 0, 0);
            return ['total' => 0, 'scanned' => 0, 'skipped' => 0, 'with_face' => 0, 'errors' => 0];
        }

        $files = [];
        $this->collectImageFiles($userFolder, $files);
        $total = count($files);

        $this->setPeopleProgress($userId, 'scanning', $total, 0);

        $scanned = 0;
        $skipped = 0;
        $withFace = 0;
        $errors = 0;
        $processed = 0;

        foreach ($files as $file) {
            try {
                $fileId = $file->getId();
                $mtime = $file->getMTime();

                if ($fileId === null || $file->getSize() === 0) {
                    $skipped++;
                    $processed++;
                    continue;
                }

                // Skip if already scanned and file unchanged (unless forced)
                if (!$force && $this->fileFaceMapper->isAlreadyScanned($userId, $fileId, $mtime)) {
                    try {
                        $existing = $this->fileFaceMapper->findByFileId($userId, $fileId);
                        if ($existing->getHasFace()) {
                            $withFace++;
                        }
                    } catch (DoesNotExistException) {
                        // Should not happen since isAlreadyScanned returned true
                    }
                    $skipped++;
                    $processed++;
                    if ($processed % 50 === 0 || $processed === $total) {
                        $this->setPeopleProgress($userId, 'scanning', $total, $processed);
                    }
                    continue;
                }

                $relativePath = $this->getUserRelativePath($userId, $file);
                $faceData = $this->detectFaceSignature($userId, $file);

                $hasFace = $faceData['has_face'];
                $signature = $hasFace ? (string) ($faceData['signature'] ?? '') : null;
                $confidence = $hasFace ? (float) ($faceData['confidence'] ?? 0.0) : null;

                $this->upsertFaceRecord(
                    $userId,
                    $fileId,
                    $relativePath,
                    $file->getSize(),
                    $file->getMimeType(),
                    $hasFace,
                    $signature,
                    $confidence,
                    $mtime,
                );

                $scanned++;
                if ($hasFace) {
                    $withFace++;
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->error('Failed to extract face signature from file', [
                    'userId' => $userId,
                    'filePath' => $file->getPath(),
                    'exception' => $e,
                ]);
            }

            $processed++;
            if ($processed % 50 === 0 || $processed === $total) {
                $this->setPeopleProgress($userId, 'scanning', $total, $processed);
            }
        }

        $this->setPeopleProgress($userId, 'completed', $total, $total);

        $this->logger->info('People scan completed', [
            'userId' => $userId,
            'total' => $total,
            'scanned' => $scanned,
            'skipped' => $skipped,
            'with_face' => $withFace,
            'errors' => $errors,
        ]);

        return [
            'total' => $total,
            'scanned' => $scanned,
            'skipped' => $skipped,
            'with_face' => $withFace,
            'errors' => $errors,
        ];
    }

    /**
     * Get people scan progress for a user.
     *
     * @return array{status: string, total: int, processed: int, updated_at: string}
     */
    public function getPeopleScanProgress(string $userId): array
    {
        $raw = $this->config->getUserValue($userId, Application::APP_ID, 'people_scan_progress', '');
        if ($raw === '') {
            return [
                'status' => 'idle',
                'total' => 0,
                'processed' => 0,
                'updated_at' => '',
            ];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [
                'status' => 'idle',
                'total' => 0,
                'processed' => 0,
                'updated_at' => '',
            ];
        }

        return [
            'status' => (string) ($data['status'] ?? 'idle'),
            'total' => (int) ($data['total'] ?? 0),
            'processed' => (int) ($data['processed'] ?? 0),
            'updated_at' => (string) ($data['updated_at'] ?? ''),
        ];
    }

    /**
     * Scan the user's image files for EXIF GPS data and cache results in DB.
     *
     * Only new/changed files are processed unless $force is true.
     * Files without GPS coordinates are also recorded (has_location = false)
     * so they are not re-scanned on subsequent runs.
     *
     * @return array{total: int, scanned: int, skipped: int, with_location: int, errors: int}
     */
    public function scanLocationData(string $userId, bool $force = false): array
    {
        $this->setLocationProgress($userId, 'scanning', 0, 0);

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
        } catch (NotFoundException) {
            $this->setLocationProgress($userId, 'error', 0, 0);
            return ['total' => 0, 'scanned' => 0, 'skipped' => 0, 'with_location' => 0, 'errors' => 0];
        }

        $files = [];
        $this->collectImageFiles($userFolder, $files);
        $total = count($files);

        $this->setLocationProgress($userId, 'scanning', $total, 0);

        $scanned = 0;
        $skipped = 0;
        $withLocation = 0;
        $errors = 0;
        $processed = 0;

        foreach ($files as $file) {
            try {
                $fileId = $file->getId();
                $mtime = $file->getMTime();

                if ($fileId === null || $file->getSize() === 0) {
                    $skipped++;
                    $processed++;
                    continue;
                }

                // Skip if already scanned and file unchanged (unless forced)
                if (!$force && $this->fileLocationMapper->isAlreadyScanned($userId, $fileId, $mtime)) {
                    // Count existing location records toward the total
                    try {
                        $existing = $this->fileLocationMapper->findByFileId($userId, $fileId);
                        if ($existing->getHasLocation()) {
                            $withLocation++;
                        }
                    } catch (DoesNotExistException) {
                        // Should not happen since isAlreadyScanned returned true
                    }
                    $skipped++;
                    $processed++;
                    if ($processed % 50 === 0 || $processed === $total) {
                        $this->setLocationProgress($userId, 'scanning', $total, $processed);
                    }
                    continue;
                }

                $relativePath = $this->getUserRelativePath($userId, $file);
                $coords = $this->extractGpsCoordinates($file);

                $this->upsertLocationRecord(
                    $userId,
                    $fileId,
                    $relativePath,
                    $file->getSize(),
                    $file->getMimeType(),
                    $coords !== null,
                    $coords['lat'] ?? null,
                    $coords['lng'] ?? null,
                    $mtime,
                );

                $scanned++;
                if ($coords !== null) {
                    $withLocation++;
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->error('Failed to extract location from file', [
                    'userId' => $userId,
                    'filePath' => $file->getPath(),
                    'exception' => $e,
                ]);
            }

            $processed++;
            if ($processed % 50 === 0 || $processed === $total) {
                $this->setLocationProgress($userId, 'scanning', $total, $processed);
            }
        }

        $this->setLocationProgress($userId, 'completed', $total, $total);

        $this->logger->info('Location scan completed', [
            'userId' => $userId,
            'total' => $total,
            'scanned' => $scanned,
            'skipped' => $skipped,
            'with_location' => $withLocation,
            'errors' => $errors,
        ]);

        return [
            'total' => $total,
            'scanned' => $scanned,
            'skipped' => $skipped,
            'with_location' => $withLocation,
            'errors' => $errors,
        ];
    }

    /**
     * Build location markers from the cached DB table (fast read, no EXIF I/O).
     *
     * @return array{markers: array<int, array>, total_markers: int, total_photos_with_location: int}
     */
    public function getLocationMarkers(string $userId, string $scope = self::SCOPE_ALL): array
    {
        $scope = $this->normalizeScope($scope);

        $locationRecords = $this->fileLocationMapper->findWithLocation($userId, $scope);

        $markers = [];
        $photosWithLocation = 0;

        foreach ($locationRecords as $record) {
            $photosWithLocation++;
            $lat = $record->getLat();
            $lng = $record->getLng();

            if ($lat === null || $lng === null) {
                continue;
            }

            $key = sprintf('%.4f,%.4f', $lat, $lng);

            if (!isset($markers[$key])) {
                $markers[$key] = [
                    'id' => $key,
                    'lat' => (float) sprintf('%.6f', $lat),
                    'lng' => (float) sprintf('%.6f', $lng),
                    'count' => 0,
                    'files' => [],
                ];
            }

            $markers[$key]['count']++;
            if (count($markers[$key]['files']) < 200) {
                $markers[$key]['files'][] = [
                    'fileId' => $record->getFileId(),
                    'filePath' => $record->getFilePath(),
                    'mimeType' => $record->getMimeType(),
                    'fileSize' => $record->getFileSize(),
                    'lat' => (float) sprintf('%.6f', $lat),
                    'lng' => (float) sprintf('%.6f', $lng),
                ];
            }
        }

        $markerList = array_values($markers);
        usort($markerList, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'markers' => $markerList,
            'total_markers' => count($markerList),
            'total_photos_with_location' => $photosWithLocation,
        ];
    }

    /**
     * Get location scan progress for a user.
     *
     * @return array{status: string, total: int, processed: int, updated_at: string}
     */
    public function getLocationScanProgress(string $userId): array
    {
        $raw = $this->config->getUserValue($userId, Application::APP_ID, 'location_scan_progress', '');
        if ($raw === '') {
            return [
                'status' => 'idle',
                'total' => 0,
                'processed' => 0,
                'updated_at' => '',
            ];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [
                'status' => 'idle',
                'total' => 0,
                'processed' => 0,
                'updated_at' => '',
            ];
        }

        return [
            'status' => (string) ($data['status'] ?? 'idle'),
            'total' => (int) ($data['total'] ?? 0),
            'processed' => (int) ($data['processed'] ?? 0),
            'updated_at' => (string) ($data['updated_at'] ?? ''),
        ];
    }

    /**
     * @return array{has_face: bool, signature?: string, confidence?: float}
     */
    public function detectFaceSignature(string $userId, File $file): array
    {
        if (!function_exists('curl_init')) {
            return ['has_face' => false];
        }

        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, self::FACE_SUPPORTED_MIME_TYPES, true)) {
            return ['has_face' => false];
        }

        $classifyEndpoint = trim($this->config->getAppValue(
            Application::APP_ID,
            'ml_classifier_endpoint',
            'http://photodedup-ml-worker:8008/classify',
        ));
        if ($classifyEndpoint === '') {
            return ['has_face' => false];
        }

        $faceEndpoint = preg_replace('#/classify/?$#', '/face-signature', $classifyEndpoint);
        if (!is_string($faceEndpoint) || $faceEndpoint === '') {
            $faceEndpoint = rtrim($classifyEndpoint, '/') . '/face-signature';
        }

        $timeout = max(2, min(60, (int) $this->config->getAppValue(Application::APP_ID, 'ml_classifier_timeout_seconds', '20')));
        $maxFileBytes = max(512 * 1024, min(100 * 1024 * 1024, (int) $this->config->getAppValue(Application::APP_ID, 'insights_people_max_file_bytes', '52428800')));
        $minFaceConfidence = max(0.0, min(1.0, (float) $this->config->getAppValue(Application::APP_ID, 'insights_people_min_face_confidence', '0.35')));
        $minFamilyConfidence = max(0.0, min(1.0, (float) $this->config->getAppValue(Application::APP_ID, 'insights_people_min_family_confidence', '0.20')));
        $requireFamilyCategory = $this->isTruthyAppValue('insights_people_require_family_category', true);

        if ($file->getSize() <= 0 || $file->getSize() > $maxFileBytes) {
            return ['has_face' => false];
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'pdd_face_');
        if ($tempFile === false) {
            return ['has_face' => false];
        }

        try {
            if (!$this->copyFileToLocalTemp($file, $tempFile, $maxFileBytes)) {
                return ['has_face' => false];
            }

            $headers = ['Accept: application/json'];
            $token = trim($this->config->getAppValue(Application::APP_ID, 'ml_classifier_token', ''));
            if ($token !== '') {
                $headers[] = 'Authorization: Bearer ' . $token;
            }

            $payload = [
                'file' => new \CURLFile($tempFile, $mimeType, basename($this->getUserRelativePath($userId, $file))),
            ];

            $ch = curl_init($faceEndpoint);
            if ($ch === false) {
                return ['has_face' => false];
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_POSTFIELDS => $payload,
            ]);

            $body = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($body === false || $statusCode < 200 || $statusCode >= 300) {
                return ['has_face' => false];
            }

            $decoded = json_decode((string) $body, true);
            if (!is_array($decoded) || !isset($decoded['has_face'])) {
                return ['has_face' => false];
            }

            if (!(bool) $decoded['has_face']) {
                return ['has_face' => false];
            }

            $signature = trim((string) ($decoded['signature'] ?? ''));
            if ($signature === '') {
                return ['has_face' => false];
            }

            $faceConfidence = (float) ($decoded['confidence'] ?? 0.0);
            if ($faceConfidence < $minFaceConfidence) {
                return ['has_face' => false];
            }

            if ($requireFamilyCategory && !$this->isFamilyCategoryImage($tempFile, $mimeType, $classifyEndpoint, $timeout, $minFamilyConfidence, $userId)) {
                return ['has_face' => false];
            }

            return [
                'has_face' => true,
                'signature' => $signature,
                'confidence' => $faceConfidence,
            ];
        } catch (\Throwable $e) {
            $this->logger->debug('Face signature detection failed', [
                'file' => $file->getPath(),
                'exception' => $e,
            ]);
            return ['has_face' => false];
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

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

    private function findClusterIndex(array $clusters, string $signature): int
    {
        foreach ($clusters as $index => $cluster) {
            if ($this->signatureMatchesCluster($cluster, $signature)) {
                return $index;
            }
        }

        return -1;
    }

    private function signatureMatchesCluster(array $cluster, string $signature): bool
    {
        $clusterSignatures = $cluster['signatures'] ?? [];
        if (!is_array($clusterSignatures) || $clusterSignatures === []) {
            $clusterSignatures = [(string) ($cluster['signature'] ?? '')];
        }

        foreach ($clusterSignatures as $clusterSignature) {
            $distance = $this->hexHammingDistance((string) $clusterSignature, $signature);
            if ($distance <= self::FACE_CLUSTER_DISTANCE) {
                return true;
            }
        }

        return false;
    }

    private function isFamilyCategoryImage(
        string $tempFile,
        string $mimeType,
        string $classifyEndpoint,
        int $timeout,
        float $minFamilyConfidence,
        string $userId,
    ): bool {
        $headers = ['Accept: application/json'];
        $token = trim($this->config->getAppValue(Application::APP_ID, 'ml_classifier_token', ''));
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $payload = [
            'file' => new \CURLFile($tempFile, $mimeType, basename($tempFile)),
            'candidate_labels' => 'document,meme,nature,family,object',
        ];

        $ch = curl_init($classifyEndpoint);
        if ($ch === false) {
            return true;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_POSTFIELDS => $payload,
        ]);

        $body = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $statusCode < 200 || $statusCode >= 300) {
            return true;
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            return true;
        }

        $category = strtolower(trim((string) ($decoded['category'] ?? '')));
        $confidence = (float) ($decoded['confidence'] ?? 0.0);

        if ($category !== 'family') {
            $this->logger->debug('Face rejected by family-category check', [
                'userId' => $userId,
                'category' => $category,
                'confidence' => $confidence,
            ]);
            return false;
        }

        return $confidence >= $minFamilyConfidence;
    }

    private function isTruthyAppValue(string $key, bool $default): bool
    {
        $defaultValue = $default ? 'true' : 'false';
        $rawValue = strtolower(trim($this->config->getAppValue(Application::APP_ID, $key, $defaultValue)));

        return in_array($rawValue, ['1', 'true', 'yes', 'on'], true);
    }

    private function hexHammingDistance(string $a, string $b): int
    {
        $a = strtolower(trim($a));
        $b = strtolower(trim($b));

        if (strlen($a) !== strlen($b) || $a === '' || !ctype_xdigit($a) || !ctype_xdigit($b)) {
            return PHP_INT_MAX;
        }

        static $bitCounts = [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4];

        $distance = 0;
        $length = strlen($a);
        for ($i = 0; $i < $length; $i++) {
            $xa = hexdec($a[$i]);
            $xb = hexdec($b[$i]);
            $distance += $bitCounts[$xa ^ $xb];
        }

        return $distance;
    }

    private function normalizeScope(string $scope): string
    {
        return $scope === self::SCOPE_PHOTOS ? self::SCOPE_PHOTOS : self::SCOPE_ALL;
    }

    private function matchesScope(string $relativePath, string $scope): bool
    {
        if ($scope !== self::SCOPE_PHOTOS) {
            return true;
        }

        return $relativePath === 'Photos'
            || str_starts_with($relativePath, 'Photos/')
            || $relativePath === 'photos'
            || str_starts_with($relativePath, 'photos/');
    }

    private function collectImageFiles(Folder $folder, array &$result): void
    {
        try {
            $nodes = $folder->getDirectoryListing();
        } catch (\Throwable) {
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
                if (in_array($node->getMimeType(), Application::SUPPORTED_MIME_TYPES, true)) {
                    $result[] = $node;
                }
            }
        }
    }

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
     * Insert or update a location record in the database.
     */
    private function upsertLocationRecord(
        string $userId,
        int $fileId,
        string $filePath,
        int $fileSize,
        string $mimeType,
        bool $hasLocation,
        ?float $lat,
        ?float $lng,
        int $fileMtime,
    ): void {
        try {
            $existing = $this->fileLocationMapper->findByFileId($userId, $fileId);
            $existing->setFilePath($filePath);
            $existing->setFileSize($fileSize);
            $existing->setMimeType($mimeType);
            $existing->setHasLocation($hasLocation);
            $existing->setLat($lat);
            $existing->setLng($lng);
            $existing->setFileMtime($fileMtime);
            $existing->setScannedAt(new DateTime());
            $this->fileLocationMapper->update($existing);
        } catch (DoesNotExistException) {
            $entity = new FileLocation();
            $entity->setUserId($userId);
            $entity->setFileId($fileId);
            $entity->setFilePath($filePath);
            $entity->setFileSize($fileSize);
            $entity->setMimeType($mimeType);
            $entity->setHasLocation($hasLocation);
            $entity->setLat($lat);
            $entity->setLng($lng);
            $entity->setFileMtime($fileMtime);
            $entity->setScannedAt(new DateTime());
            $this->fileLocationMapper->insert($entity);
        }
    }

    /**
     * Store location-scan progress in user config for frontend polling.
     */
    private function setLocationProgress(string $userId, string $status, int $total, int $processed): void
    {
        $data = json_encode([
            'status' => $status,
            'total' => $total,
            'processed' => $processed,
            'updated_at' => (new DateTime())->format(\DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR);

        $this->config->setUserValue($userId, Application::APP_ID, 'location_scan_progress', $data);
    }

    /**
     * Insert or update a face record in the database.
     */
    private function upsertFaceRecord(
        string $userId,
        int $fileId,
        string $filePath,
        int $fileSize,
        string $mimeType,
        bool $hasFace,
        ?string $faceSignature,
        ?float $faceConfidence,
        int $fileMtime,
    ): void {
        try {
            $existing = $this->fileFaceMapper->findByFileId($userId, $fileId);
            $existing->setFilePath($filePath);
            $existing->setFileSize($fileSize);
            $existing->setMimeType($mimeType);
            $existing->setHasFace($hasFace);
            $existing->setFaceSignature($faceSignature);
            $existing->setFaceConfidence($faceConfidence);
            $existing->setFileMtime($fileMtime);
            $existing->setScannedAt(new DateTime());
            $this->fileFaceMapper->update($existing);
        } catch (DoesNotExistException) {
            $entity = new FileFace();
            $entity->setUserId($userId);
            $entity->setFileId($fileId);
            $entity->setFilePath($filePath);
            $entity->setFileSize($fileSize);
            $entity->setMimeType($mimeType);
            $entity->setHasFace($hasFace);
            $entity->setFaceSignature($faceSignature);
            $entity->setFaceConfidence($faceConfidence);
            $entity->setFileMtime($fileMtime);
            $entity->setScannedAt(new DateTime());
            $this->fileFaceMapper->insert($entity);
        }
    }

    /**
     * Store people-scan progress in user config for frontend polling.
     */
    private function setPeopleProgress(string $userId, string $status, int $total, int $processed): void
    {
        $data = json_encode([
            'status' => $status,
            'total' => $total,
            'processed' => $processed,
            'updated_at' => (new DateTime())->format(\DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR);

        $this->config->setUserValue($userId, Application::APP_ID, 'people_scan_progress', $data);
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    protected function extractGpsCoordinates(File $file): ?array
    {
        $exif = $this->extractExif($file);
        if ($exif === []) {
            return null;
        }

        if (empty($exif['GPSLatitude']) || empty($exif['GPSLatitudeRef']) || empty($exif['GPSLongitude']) || empty($exif['GPSLongitudeRef'])) {
            return null;
        }

        $lat = $this->gpsToDecimal($exif['GPSLatitude'], (string) $exif['GPSLatitudeRef']);
        $lng = $this->gpsToDecimal($exif['GPSLongitude'], (string) $exif['GPSLongitudeRef']);

        if ($lat === null || $lng === null) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }

    private function extractExif(File $file): array
    {
        $mime = $file->getMimeType();
        if ($mime !== 'image/jpeg' && $mime !== 'image/tiff') {
            return [];
        }

        try {
            $tempFile = tempnam(sys_get_temp_dir(), 'pdd_exif_');
            if ($tempFile === false) {
                return [];
            }

            try {
                $handle = $file->fopen('r');
                if (!is_resource($handle)) {
                    return [];
                }

                $output = fopen($tempFile, 'wb');
                if (!is_resource($output)) {
                    fclose($handle);
                    return [];
                }

                $copied = 0;
                $maxBytes = max(
                    256 * 1024,
                    min(
                        16 * 1024 * 1024,
                        (int) $this->config->getAppValue(
                            Application::APP_ID,
                            'insights_location_exif_read_bytes',
                            (string) self::LOCATION_DEFAULT_EXIF_READ_BYTES,
                        ),
                    ),
                );

                try {
                    while (!feof($handle)) {
                        $chunk = fread($handle, 1024 * 1024);
                        if ($chunk === false) {
                            break;
                        }
                        $length = strlen($chunk);
                        if ($length === 0) {
                            continue;
                        }
                        if (fwrite($output, $chunk) === false) {
                            break;
                        }
                        $copied += $length;
                        // EXIF data lives in the file header.  Once we have
                        // copied enough bytes, stop reading and proceed with
                        // the EXIF extraction on what we already have.
                        if ($copied >= $maxBytes) {
                            break;
                        }
                    }
                } finally {
                    fclose($handle);
                    fclose($output);
                }

                if ($copied === 0) {
                    return [];
                }

                $exif = @exif_read_data($tempFile, 'ANY_TAG', true);
                if (!is_array($exif)) {
                    return [];
                }

                return $this->flattenExif($exif);
            } finally {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }
        } catch (\Throwable) {
            return [];
        }
    }

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

    private function gpsToDecimal(mixed $coordinate, string $hemisphere): ?float
    {
        if (!is_array($coordinate) || count($coordinate) < 3) {
            return null;
        }

        $degrees = $this->parseExifNumber($coordinate[0]);
        $minutes = $this->parseExifNumber($coordinate[1]);
        $seconds = $this->parseExifNumber($coordinate[2]);

        if ($degrees === null || $minutes === null || $seconds === null) {
            return null;
        }

        $decimal = $degrees + ($minutes / 60.0) + ($seconds / 3600.0);
        $ref = strtoupper(trim($hemisphere));
        if ($ref === 'S' || $ref === 'W') {
            $decimal *= -1;
        }

        return $decimal;
    }

    private function parseExifNumber(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (str_contains($value, '/')) {
            $parts = explode('/', $value, 2);
            $num = (float) ($parts[0] ?? 0);
            $den = (float) ($parts[1] ?? 0);
            if ($den == 0.0) {
                return null;
            }
            return $num / $den;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
