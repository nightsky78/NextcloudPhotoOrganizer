<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Service;

use DateTime;
use OCA\PhotoDedup\AppInfo\Application;
use OCA\PhotoDedup\Db\FaceInstance;
use OCA\PhotoDedup\Db\FaceInstanceMapper;
use OCA\PhotoDedup\Db\FaceSignatureLabelMapper;
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
    private const FACE_EMBEDDING_SIGNATURE_PREFIX = 'emb:v1:';
    private const MAX_CLUSTER_SIGNATURES = 24;
    private const PEOPLE_DEFAULT_CLUSTER_LIMIT = 10;
    private const PEOPLE_DEFAULT_FILES_PER_CLUSTER = 50;
    private const FACE_SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/bmp',
        'image/tiff',
    ];
    private const LOCATION_DEFAULT_EXIF_READ_BYTES = 2097152;
    private const PEOPLE_PROGRESS_WRITE_EVERY = 5;
    private const PEOPLE_SCAN_STALE_SECONDS = 180;

    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly FileFaceMapper $fileFaceMapper,
        private readonly FaceInstanceMapper $faceInstanceMapper,
        private readonly FaceSignatureLabelMapper $faceSignatureLabelMapper,
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
    * @return array{clusters: array<int, array>, total_clusters: int, total_face_images: int, cluster_limit: int, files_per_cluster: int, reference_candidates: array<int, array>}
     */
    public function getPeopleClusters(
        string $userId,
        string $scope = self::SCOPE_ALL,
        int $clusterLimit = self::PEOPLE_DEFAULT_CLUSTER_LIMIT,
        int $filesPerCluster = self::PEOPLE_DEFAULT_FILES_PER_CLUSTER,
    ): array
    {
        $scope = $this->normalizeScope($scope);
        $clusterLimit = max(1, min(50, $clusterLimit));
        $filesPerCluster = max(1, min(200, $filesPerCluster));

        $instanceRecords = $this->faceInstanceMapper->findWithFace($userId, $scope);

        $labelMap = $this->faceSignatureLabelMapper->getLabelMapForUser($userId);

        $uniqueFaceImageKeys = [];
        $fileFaceCounts = [];
        foreach ($instanceRecords as $record) {
            $uniqueFaceImageKeys[$record->getFileId() . ':' . $record->getFilePath()] = true;

            $recordSignature = trim((string) ($record->getFaceSignature() ?? ''));
            if ($recordSignature === '') {
                continue;
            }

            $recordFileId = $record->getFileId();
            $fileFaceCounts[$recordFileId] = ($fileFaceCounts[$recordFileId] ?? 0) + 1;
        }

        $referenceCandidates = $this->buildReferenceCandidates($instanceRecords, $fileFaceCounts, 120);
        $labelMap = $this->filterLabelMapToSingleFaceReferences($labelMap, $instanceRecords, $fileFaceCounts);
        $referenceProfiles = $this->buildReferenceProfilesFromLabels($labelMap);

        if ($referenceProfiles === []) {
            return [
                'clusters' => [],
                'total_clusters' => 0,
                'total_face_images' => count($uniqueFaceImageKeys),
                'cluster_limit' => $clusterLimit,
                'files_per_cluster' => $filesPerCluster,
                'reference_candidates' => $referenceCandidates,
            ];
        }

        $clusters = $this->buildReferenceClusters($instanceRecords, $referenceProfiles, $filesPerCluster);
        $totalClusters = count($clusters);
        $clusters = array_slice($clusters, 0, $clusterLimit);

        return [
            'clusters' => $clusters,
            'total_clusters' => $totalClusters,
            'total_face_images' => count($uniqueFaceImageKeys),
            'cluster_limit' => $clusterLimit,
            'files_per_cluster' => $filesPerCluster,
            'reference_candidates' => $referenceCandidates,
        ];
    }

    /**
     * @param array<int, FaceInstance> $instanceRecords
     * @param array<int, int> $fileFaceCounts
     * @return array<int, array<string, mixed>>
     */
    private function buildReferenceCandidates(array $instanceRecords, array $fileFaceCounts, int $limit): array
    {
        $limit = max(1, min(300, $limit));

        $bestBySignature = [];
        foreach ($instanceRecords as $record) {
            $signature = trim((string) ($record->getFaceSignature() ?? ''));
            if ($signature === '') {
                continue;
            }

            $fileId = $record->getFileId();
            if (($fileFaceCounts[$fileId] ?? 0) !== 1) {
                continue;
            }

            $candidate = [
                'fileId' => $fileId,
                'filePath' => $record->getFilePath(),
                'mimeType' => $record->getMimeType(),
                'fileSize' => $record->getFileSize(),
                'faceConfidence' => $record->getFaceConfidence() !== null ? (float) $record->getFaceConfidence() : 0.0,
                'faceSignature' => $signature,
                'faceCountInFile' => 1,
            ];

            $existing = $bestBySignature[$signature] ?? null;
            if ($existing === null || (float) ($candidate['faceConfidence'] ?? 0.0) > (float) ($existing['faceConfidence'] ?? 0.0)) {
                $bestBySignature[$signature] = $candidate;
            }
        }

        $candidates = array_values($bestBySignature);
        usort(
            $candidates,
            static fn(array $a, array $b): int => ((float) ($b['faceConfidence'] ?? 0.0) <=> (float) ($a['faceConfidence'] ?? 0.0))
                ?: ((int) ($b['fileSize'] ?? 0) <=> (int) ($a['fileSize'] ?? 0))
                ?: ((int) ($a['fileId'] ?? 0) <=> (int) ($b['fileId'] ?? 0))
        );

        return array_slice($candidates, 0, $limit);
    }

    /**
     * @param array<string, string> $labelMap
     * @param array<int, FaceInstance> $instanceRecords
     * @param array<int, int> $fileFaceCounts
     * @return array<string, string>
     */
    private function filterLabelMapToSingleFaceReferences(array $labelMap, array $instanceRecords, array $fileFaceCounts): array
    {
        if ($labelMap === [] || $instanceRecords === []) {
            return [];
        }

        $eligibleSignatures = [];
        foreach ($instanceRecords as $record) {
            $signature = trim((string) ($record->getFaceSignature() ?? ''));
            if ($signature === '') {
                continue;
            }

            $fileId = $record->getFileId();
            if (($fileFaceCounts[$fileId] ?? 0) !== 1) {
                continue;
            }

            $eligibleSignatures[$signature] = true;
        }

        $filtered = [];
        foreach ($labelMap as $signature => $label) {
            $signature = trim((string) $signature);
            $label = trim((string) $label);
            if ($signature === '' || $label === '') {
                continue;
            }

            if (!isset($eligibleSignatures[$signature])) {
                continue;
            }

            $filtered[$signature] = $label;
        }

        return $filtered;
    }

    public function setFaceSignatureLabel(string $userId, string $faceSignature, string $labelName): void
    {
        $signature = trim($faceSignature);
        if ($signature === '') {
            return;
        }

        $this->faceSignatureLabelMapper->setLabel($userId, $signature, $labelName);
    }

    /**
     * @param array<string, string> $labelMap
     * @return array<string, array{vector: array<float>, reference_signatures: array<int, string>}>
     */
    private function buildReferenceProfilesFromLabels(array $labelMap): array
    {
        $vectorsByPerson = [];
        $signaturesByPerson = [];

        foreach ($labelMap as $signature => $label) {
            $person = trim((string) $label);
            if ($person === '') {
                continue;
            }

            $vector = $this->parseEmbeddingSignature((string) $signature);
            if ($vector === null) {
                continue;
            }

            if (!isset($vectorsByPerson[$person])) {
                $vectorsByPerson[$person] = [];
            }
            if (!isset($signaturesByPerson[$person])) {
                $signaturesByPerson[$person] = [];
            }

            $vectorsByPerson[$person][] = $vector;
            $signaturesByPerson[$person][] = (string) $signature;
        }

        $profiles = [];
        foreach ($vectorsByPerson as $person => $vectors) {
            if ($vectors === []) {
                continue;
            }

            $dimension = count($vectors[0]);
            if ($dimension === 0) {
                continue;
            }

            $sum = array_fill(0, $dimension, 0.0);
            foreach ($vectors as $vector) {
                if (count($vector) !== $dimension) {
                    continue;
                }

                for ($i = 0; $i < $dimension; $i++) {
                    $sum[$i] += (float) $vector[$i];
                }
            }

            $count = count($vectors);
            if ($count <= 0) {
                continue;
            }

            $centroid = [];
            for ($i = 0; $i < $dimension; $i++) {
                $centroid[$i] = $sum[$i] / $count;
            }

            $normSquared = 0.0;
            foreach ($centroid as $value) {
                $normSquared += $value * $value;
            }

            if ($normSquared <= 0.0) {
                continue;
            }

            $norm = sqrt($normSquared);
            foreach ($centroid as $index => $value) {
                $centroid[$index] = $value / $norm;
            }

            $profiles[$person] = [
                'vector' => $centroid,
                'reference_signatures' => array_values(array_unique($signaturesByPerson[$person] ?? [], SORT_STRING)),
            ];
        }

        return $profiles;
    }

    /**
     * @param array<int, FaceInstance> $instanceRecords
     * @param array<string, array{vector: array<float>, reference_signatures: array<int, string>}> $profiles
     * @return array<int, array<string, mixed>>
     */
    private function buildReferenceClusters(array $instanceRecords, array $profiles, int $filesPerCluster): array
    {
        $minSimilarity = max(0.5, min(0.99, (float) $this->config->getAppValue(Application::APP_ID, 'insights_people_reference_min_similarity', '0.74')));
        $minMargin = max(0.0, min(0.2, (float) $this->config->getAppValue(Application::APP_ID, 'insights_people_reference_min_margin', '0.01')));

        $fileFaceCounts = [];
        foreach ($instanceRecords as $record) {
            $recordSignature = trim((string) ($record->getFaceSignature() ?? ''));
            if ($recordSignature === '') {
                continue;
            }

            $recordFileId = $record->getFileId();
            $fileFaceCounts[$recordFileId] = ($fileFaceCounts[$recordFileId] ?? 0) + 1;
        }

        $buckets = [];
        $personByReferenceSignature = [];
        foreach ($profiles as $person => $profile) {
            $buckets[$person] = [
                'name' => $person,
                'person_key' => $person,
                'reference_signatures' => $profile['reference_signatures'],
                'filesById' => [],
            ];

            foreach ($profile['reference_signatures'] as $referenceSignature) {
                $referenceSignature = trim((string) $referenceSignature);
                if ($referenceSignature === '') {
                    continue;
                }

                $personByReferenceSignature[$referenceSignature] = $person;
            }
        }

        foreach ($instanceRecords as $record) {
            $signature = trim((string) ($record->getFaceSignature() ?? ''));
            if ($signature === '') {
                continue;
            }

            $mappedReferencePerson = $personByReferenceSignature[$signature] ?? null;
            if (is_string($mappedReferencePerson) && isset($profiles[$mappedReferencePerson])) {
                $fileId = $record->getFileId();
                $entry = [
                    'fileId' => $fileId,
                    'filePath' => $record->getFilePath(),
                    'mimeType' => $record->getMimeType(),
                    'fileSize' => $record->getFileSize(),
                    'faceConfidence' => $record->getFaceConfidence() !== null ? (float) $record->getFaceConfidence() : 0.0,
                    'faceSignature' => $signature,
                    'faceCountInFile' => (int) ($fileFaceCounts[$fileId] ?? 1),
                    'matchScore' => 1.0,
                ];

                $existing = $buckets[$mappedReferencePerson]['filesById'][$fileId] ?? null;
                if ($existing === null || (float) ($entry['matchScore'] ?? 0.0) > (float) ($existing['matchScore'] ?? 0.0)) {
                    $buckets[$mappedReferencePerson]['filesById'][$fileId] = $entry;
                }

                continue;
            }

            $embedding = $this->parseEmbeddingSignature($signature);
            if ($embedding === null) {
                continue;
            }

            $bestPerson = null;
            $bestScore = -1.0;
            $secondScore = -1.0;

            foreach ($profiles as $person => $profile) {
                $score = $this->cosineSimilarity($embedding, $profile['vector']);
                if ($score > $bestScore) {
                    $secondScore = $bestScore;
                    $bestScore = $score;
                    $bestPerson = $person;
                } elseif ($score > $secondScore) {
                    $secondScore = $score;
                }
            }

            if ($bestPerson === null || $bestScore < $minSimilarity || ($bestScore - max($secondScore, -1.0)) < $minMargin) {
                continue;
            }

            $fileId = $record->getFileId();
            $entry = [
                'fileId' => $fileId,
                'filePath' => $record->getFilePath(),
                'mimeType' => $record->getMimeType(),
                'fileSize' => $record->getFileSize(),
                'faceConfidence' => $record->getFaceConfidence() !== null ? (float) $record->getFaceConfidence() : 0.0,
                'faceSignature' => $signature,
                'faceCountInFile' => (int) ($fileFaceCounts[$fileId] ?? 1),
                'matchScore' => $bestScore,
            ];

            $existing = $buckets[$bestPerson]['filesById'][$fileId] ?? null;
            if ($existing === null || (float) ($entry['matchScore'] ?? 0.0) > (float) ($existing['matchScore'] ?? 0.0)) {
                $buckets[$bestPerson]['filesById'][$fileId] = $entry;
            }
        }

        $clusters = [];
        foreach ($buckets as $person => $bucket) {
            $files = array_values($bucket['filesById']);
            if ($files === []) {
                continue;
            }

            usort(
                $files,
                static fn(array $a, array $b): int => ((float) ($b['matchScore'] ?? 0.0) <=> (float) ($a['matchScore'] ?? 0.0))
                    ?: ((float) ($b['faceConfidence'] ?? 0.0) <=> (float) ($a['faceConfidence'] ?? 0.0))
                    ?: ((int) ($b['fileSize'] ?? 0) <=> (int) ($a['fileSize'] ?? 0))
            );

            $total = count($files);
            $visible = array_slice($files, 0, $filesPerCluster);
            $referenceSignatures = $bucket['reference_signatures'];

            $clusters[] = [
                'id' => 'person-' . substr(sha1($person), 0, 12),
                'name' => $person,
                'person_key' => $person,
                'count' => $total,
                'top_confidence' => (float) ($files[0]['faceConfidence'] ?? 0.0),
                'label_signature' => $referenceSignatures[0] ?? '',
                'cluster_signatures' => $referenceSignatures,
                'files' => $visible,
                'has_more_files' => $total > count($visible),
                'next_offset' => count($visible),
            ];
        }

        usort($clusters, static fn(array $a, array $b): int => ((float) ($b['top_confidence'] ?? 0.0) <=> (float) ($a['top_confidence'] ?? 0.0)) ?: ((int) ($b['count'] ?? 0) <=> (int) ($a['count'] ?? 0)));

        return $clusters;
    }

    /**
     * @param array<int, string> $signatures
     * @return array{files: array<int, array>, total: int, offset: int, limit: int, has_more: bool, next_offset: int}
     */
    public function getPeopleClusterFiles(
        string $userId,
        array $signatures,
        string $scope = self::SCOPE_ALL,
        int $offset = 0,
        int $limit = self::PEOPLE_DEFAULT_FILES_PER_CLUSTER,
    ): array {
        $scope = $this->normalizeScope($scope);
        $offset = max(0, $offset);
        $limit = max(1, min(200, $limit));

        $normalizedSignatures = [];
        foreach ($signatures as $signature) {
            $trimmed = trim((string) $signature);
            if ($trimmed === '') {
                continue;
            }
            $normalizedSignatures[$trimmed] = true;
        }

        $signatureList = array_keys($normalizedSignatures);
        if ($signatureList === []) {
            return [
                'files' => [],
                'total' => 0,
                'offset' => $offset,
                'limit' => $limit,
                'has_more' => false,
                'next_offset' => $offset,
            ];
        }

        $records = $this->faceInstanceMapper->findBySignatures($userId, $signatureList, $scope);

        $fileFaceCounts = [];
        foreach ($records as $record) {
            $recordFileId = $record->getFileId();
            $fileFaceCounts[$recordFileId] = ($fileFaceCounts[$recordFileId] ?? 0) + 1;
        }

        $filesById = [];
        foreach ($records as $record) {
            $fileId = $record->getFileId();
            if (isset($filesById[$fileId])) {
                continue;
            }

            $filesById[$fileId] = [
                'fileId' => $fileId,
                'filePath' => $record->getFilePath(),
                'mimeType' => $record->getMimeType(),
                'fileSize' => $record->getFileSize(),
                'faceConfidence' => $record->getFaceConfidence() !== null ? (float) $record->getFaceConfidence() : 0.0,
                'faceSignature' => (string) ($record->getFaceSignature() ?? ''),
                'faceCountInFile' => (int) ($fileFaceCounts[$fileId] ?? 1),
            ];
        }

        $files = array_values($filesById);
        usort(
            $files,
            static fn(array $a, array $b): int => ($b['faceConfidence'] <=> $a['faceConfidence'])
                ?: ($b['fileSize'] <=> $a['fileSize'])
                ?: ($a['fileId'] <=> $b['fileId'])
        );

        $total = count($files);
        $chunk = array_slice($files, $offset, $limit);
        $nextOffset = $offset + count($chunk);

        return [
            'files' => $chunk,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'has_more' => $nextOffset < $total,
            'next_offset' => $nextOffset,
        ];
    }

    /**
     * @return array{files: array<int, array>, total: int, offset: int, limit: int, has_more: bool, next_offset: int}
     */
    public function getPeopleClusterFilesByPerson(
        string $userId,
        string $person,
        string $scope = self::SCOPE_ALL,
        int $offset = 0,
        int $limit = self::PEOPLE_DEFAULT_FILES_PER_CLUSTER,
    ): array {
        $person = trim($person);
        if ($person === '') {
            return [
                'files' => [],
                'total' => 0,
                'offset' => max(0, $offset),
                'limit' => max(1, min(200, $limit)),
                'has_more' => false,
                'next_offset' => max(0, $offset),
            ];
        }

        $scope = $this->normalizeScope($scope);
        $offset = max(0, $offset);
        $limit = max(1, min(200, $limit));

        $instanceRecords = $this->faceInstanceMapper->findWithFace($userId, $scope);
        if ($instanceRecords === []) {
            return [
                'files' => [],
                'total' => 0,
                'offset' => $offset,
                'limit' => $limit,
                'has_more' => false,
                'next_offset' => $offset,
            ];
        }

        $labelMap = $this->faceSignatureLabelMapper->getLabelMapForUser($userId);

        $fileFaceCounts = [];
        foreach ($instanceRecords as $record) {
            $recordSignature = trim((string) ($record->getFaceSignature() ?? ''));
            if ($recordSignature === '') {
                continue;
            }

            $recordFileId = $record->getFileId();
            $fileFaceCounts[$recordFileId] = ($fileFaceCounts[$recordFileId] ?? 0) + 1;
        }

        $labelMap = $this->filterLabelMapToSingleFaceReferences($labelMap, $instanceRecords, $fileFaceCounts);
        $profiles = $this->buildReferenceProfilesFromLabels($labelMap);
        if (!isset($profiles[$person])) {
            return [
                'files' => [],
                'total' => 0,
                'offset' => $offset,
                'limit' => $limit,
                'has_more' => false,
                'next_offset' => $offset,
            ];
        }

        $clusters = $this->buildReferenceClusters($instanceRecords, [$person => $profiles[$person]], 1000000);
        if ($clusters === []) {
            return [
                'files' => [],
                'total' => 0,
                'offset' => $offset,
                'limit' => $limit,
                'has_more' => false,
                'next_offset' => $offset,
            ];
        }

        $allFiles = $clusters[0]['files'] ?? [];
        $total = count($allFiles);
        $chunk = array_slice($allFiles, $offset, $limit);
        $nextOffset = $offset + count($chunk);

        return [
            'files' => $chunk,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'has_more' => $nextOffset < $total,
            'next_offset' => $nextOffset,
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
                    if ($processed % self::PEOPLE_PROGRESS_WRITE_EVERY === 0 || $processed === $total) {
                        $this->setPeopleProgress($userId, 'scanning', $total, $processed);
                    }
                    continue;
                }

                $relativePath = $this->getUserRelativePath($userId, $file);
                $faceDataList = $this->detectFaceSignatures($userId, $file);
                $hasFace = $faceDataList !== [];
                $signature = $hasFace ? $this->normalizeStoredFaceSignature((string) ($faceDataList[0]['signature'] ?? '')) : null;
                $confidence = $hasFace ? (float) ($faceDataList[0]['confidence'] ?? 0.0) : null;

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

                $this->replaceFaceInstances(
                    $userId,
                    $fileId,
                    $relativePath,
                    $file->getSize(),
                    $file->getMimeType(),
                    $faceDataList,
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
            if ($processed % self::PEOPLE_PROGRESS_WRITE_EVERY === 0 || $processed === $total) {
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
        $progress = $this->readScanProgress($userId, 'people_scan_progress');

        if ($progress['status'] === 'scanning' && $this->isPeopleScanStale($progress['updated_at'])) {
            $resolvedStatus = ($progress['total'] > 0 && $progress['processed'] >= $progress['total'])
                ? 'completed'
                : 'interrupted';

            $this->setPeopleProgress($userId, $resolvedStatus, $progress['total'], min($progress['processed'], $progress['total']));

            $progress['status'] = $resolvedStatus;
            $progress['processed'] = min($progress['processed'], $progress['total']);
            $progress['updated_at'] = (new DateTime())->format(\DateTimeInterface::ATOM);
        }

        return $progress;
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
        return $this->readScanProgress($userId, 'location_scan_progress');
    }

    /**
     * @return array<array{signature: string, confidence: float}>
     */
    public function detectFaceSignatures(string $userId, File $file): array
    {
        if (!function_exists('curl_init')) {
            return [];
        }

        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, self::FACE_SUPPORTED_MIME_TYPES, true)) {
            return [];
        }

        $classifyEndpoint = trim($this->config->getAppValue(
            Application::APP_ID,
            'ml_classifier_endpoint',
            'http://photodedup-ml-worker:8008/classify',
        ));
        if ($classifyEndpoint === '') {
            return [];
        }

        $faceEndpoint = preg_replace('#/classify/?$#', '/face-signature', $classifyEndpoint);
        if (!is_string($faceEndpoint) || $faceEndpoint === '') {
            $faceEndpoint = rtrim($classifyEndpoint, '/') . '/face-signature';
        }

        $timeout = max(2, min(60, (int) $this->config->getAppValue(Application::APP_ID, 'ml_classifier_timeout_seconds', '20')));
        $maxFileBytes = max(512 * 1024, min(100 * 1024 * 1024, (int) $this->config->getAppValue(Application::APP_ID, 'insights_people_max_file_bytes', '52428800')));
        $minFaceConfidence = max(0.0, min(1.0, (float) $this->config->getAppValue(Application::APP_ID, 'insights_people_min_face_confidence', '0.35')));

        if ($file->getSize() <= 0 || $file->getSize() > $maxFileBytes) {
            return [];
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'pdd_face_');
        if ($tempFile === false) {
            return [];
        }

        try {
            if (!$this->copyFileToLocalTemp($file, $tempFile, $maxFileBytes)) {
                return [];
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
                return [];
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
                return [];
            }

            $decoded = json_decode((string) $body, true);
            if (!is_array($decoded) || !isset($decoded['has_face'])) {
                return [];
            }

            if (!(bool) $decoded['has_face']) {
                return [];
            }

            $result = [];
            $decodedFaces = $decoded['faces'] ?? null;
            if (is_array($decodedFaces)) {
                foreach ($decodedFaces as $face) {
                    if (!is_array($face)) {
                        continue;
                    }

                    $signature = trim((string) ($face['signature'] ?? ''));
                    if ($signature === '') {
                        continue;
                    }

                    $faceConfidence = (float) ($face['confidence'] ?? 0.0);
                    if ($faceConfidence < $minFaceConfidence) {
                        continue;
                    }

                    $result[] = [
                        'signature' => $signature,
                        'confidence' => $faceConfidence,
                    ];
                }
            }

            if ($result === []) {
                $signature = trim((string) ($decoded['signature'] ?? ''));
                $faceConfidence = (float) ($decoded['confidence'] ?? 0.0);
                if ($signature !== '' && $faceConfidence >= $minFaceConfidence) {
                    $result[] = [
                        'signature' => $signature,
                        'confidence' => $faceConfidence,
                    ];
                }
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->debug('Face signature detection failed', [
                'file' => $file->getPath(),
                'exception' => $e,
            ]);
            return [];
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Compatibility helper for legacy call sites.
     *
     * @return array{has_face: bool, signature?: string, confidence?: float}
     */
    public function detectFaceSignature(string $userId, File $file): array
    {
        $faces = $this->detectFaceSignatures($userId, $file);
        if ($faces === []) {
            return ['has_face' => false];
        }

        return [
            'has_face' => true,
            'signature' => (string) ($faces[0]['signature'] ?? ''),
            'confidence' => (float) ($faces[0]['confidence'] ?? 0.0),
        ];
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

    /**
     * @return array<float>|null
     */
    private function parseEmbeddingSignature(string $signature): ?array
    {
        $signature = trim($signature);
        if (!str_starts_with($signature, self::FACE_EMBEDDING_SIGNATURE_PREFIX)) {
            return null;
        }

        $encoded = substr($signature, strlen(self::FACE_EMBEDDING_SIGNATURE_PREFIX));
        if ($encoded === '') {
            return null;
        }

        $padding = (4 - (strlen($encoded) % 4)) % 4;
        $encodedPadded = strtr($encoded, '-_', '+/') . str_repeat('=', $padding);

        $raw = base64_decode($encodedPadded, true);
        if ($raw === false || $raw === '') {
            return null;
        }

        $bytes = unpack('c*', $raw);
        if (!is_array($bytes) || $bytes === []) {
            return null;
        }

        $vector = [];
        $normSquared = 0.0;
        foreach ($bytes as $byte) {
            $value = ((int) $byte) / 127.0;
            $vector[] = $value;
            $normSquared += $value * $value;
        }

        if ($normSquared <= 0.0) {
            return null;
        }

        $norm = sqrt($normSquared);
        foreach ($vector as $index => $value) {
            $vector[$index] = $value / $norm;
        }

        return $vector;
    }

    /**
     * @param array<float> $a
     * @param array<float> $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $length = count($a);
        if ($length === 0 || $length !== count($b)) {
            return -1.0;
        }

        $dot = 0.0;
        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
        }

        return $dot;
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
     * Replace all stored face instances for one file with the newly detected set.
     *
     * @param array<int, array{signature: string, confidence: float}> $faceDataList
     */
    private function replaceFaceInstances(
        string $userId,
        int $fileId,
        string $filePath,
        int $fileSize,
        string $mimeType,
        array $faceDataList,
        int $fileMtime,
    ): void {
        $this->faceInstanceMapper->deleteByFileId($userId, $fileId);

        $index = 1;
        foreach ($faceDataList as $faceData) {
            $signature = $this->normalizeStoredFaceSignature((string) ($faceData['signature'] ?? ''));
            if ($signature === '') {
                continue;
            }

            $confidence = isset($faceData['confidence']) ? (float) $faceData['confidence'] : null;

            $entity = new FaceInstance();
            $entity->setUserId($userId);
            $entity->setFileId($fileId);
            $entity->setFilePath($filePath);
            $entity->setFileSize($fileSize);
            $entity->setMimeType($mimeType);
            $entity->setFaceIndex($index);
            $entity->setFaceSignature($signature);
            $entity->setFaceConfidence($confidence);
            $entity->setFileMtime($fileMtime);
            $entity->setScannedAt(new DateTime());

            $this->faceInstanceMapper->insertFaceInstance($entity);
            $index++;
        }
    }

    private function normalizeStoredFaceSignature(string $signature): string
    {
        $trimmed = trim($signature);
        if ($trimmed === '') {
            return '';
        }

        if (strlen($trimmed) <= 191) {
            return $trimmed;
        }

        return 'sig:v1:' . hash('sha256', $trimmed);
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
     * @return array{status: string, total: int, processed: int, updated_at: string}
     */
    private function readScanProgress(string $userId, string $key): array
    {
        $raw = $this->config->getUserValue($userId, Application::APP_ID, $key, '');
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
            'total' => max(0, (int) ($data['total'] ?? 0)),
            'processed' => max(0, (int) ($data['processed'] ?? 0)),
            'updated_at' => (string) ($data['updated_at'] ?? ''),
        ];
    }

    private function isPeopleScanStale(string $updatedAt): bool
    {
        if ($updatedAt === '') {
            return true;
        }

        try {
            $updated = new \DateTimeImmutable($updatedAt);
        } catch (\Throwable) {
            return true;
        }

        $age = time() - $updated->getTimestamp();
        return $age > self::PEOPLE_SCAN_STALE_SECONDS;
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
