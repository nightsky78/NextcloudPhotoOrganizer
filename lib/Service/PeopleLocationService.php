<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Service;

use OCA\PhotoDedup\AppInfo\Application;
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

    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{clusters: array<int, array>, total_clusters: int, total_face_images: int}
     */
    public function getPeopleClusters(string $userId, string $scope = self::SCOPE_ALL): array
    {
        $scope = $this->normalizeScope($scope);

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
        } catch (NotFoundException) {
            return ['clusters' => [], 'total_clusters' => 0, 'total_face_images' => 0];
        }

        $files = [];
        $this->collectImageFiles($userFolder, $files);

        $clusters = [];
        $faceImages = 0;

        foreach ($files as $file) {
            $relativePath = $this->getUserRelativePath($userId, $file);
            if (!$this->matchesScope($relativePath, $scope)) {
                continue;
            }

            $faceData = $this->detectFaceSignature($userId, $file);
            if (!$faceData['has_face']) {
                continue;
            }

            $fileId = $file->getId();
            if ($fileId === null) {
                continue;
            }

            $faceImages++;
            $signature = (string) $faceData['signature'];

            $entry = [
                'fileId' => $fileId,
                'filePath' => $relativePath,
                'mimeType' => $file->getMimeType(),
                'fileSize' => $file->getSize(),
                'faceConfidence' => (float) ($faceData['confidence'] ?? 0.0),
            ];

            $clusterIndex = $this->findClusterIndex($clusters, $signature);
            if ($clusterIndex === -1) {
                $clusters[] = [
                    'signature' => $signature,
                    'files' => [$entry],
                ];
            } else {
                $clusters[$clusterIndex]['files'][] = $entry;
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
     * @return array{markers: array<int, array>, total_markers: int, total_photos_with_location: int}
     */
    public function getLocationMarkers(string $userId, string $scope = self::SCOPE_ALL): array
    {
        $scope = $this->normalizeScope($scope);

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
        } catch (NotFoundException) {
            return ['markers' => [], 'total_markers' => 0, 'total_photos_with_location' => 0];
        }

        $files = [];
        $this->collectImageFiles($userFolder, $files);

        $markers = [];
        $photosWithLocation = 0;

        foreach ($files as $file) {
            $relativePath = $this->getUserRelativePath($userId, $file);
            if (!$this->matchesScope($relativePath, $scope)) {
                continue;
            }

            $coords = $this->extractGpsCoordinates($file);
            if ($coords === null) {
                continue;
            }

            $fileId = $file->getId();
            if ($fileId === null) {
                continue;
            }

            $photosWithLocation++;
            $lat = $coords['lat'];
            $lng = $coords['lng'];
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
                    'fileId' => $fileId,
                    'filePath' => $relativePath,
                    'mimeType' => $file->getMimeType(),
                    'fileSize' => $file->getSize(),
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
     * @return array{has_face: bool, signature?: string, confidence?: float}
     */
    private function detectFaceSignature(string $userId, File $file): array
    {
        if (!function_exists('curl_init')) {
            return ['has_face' => false];
        }

        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
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
        $maxFileBytes = max(512 * 1024, min(100 * 1024 * 1024, (int) $this->config->getAppValue(Application::APP_ID, 'ml_classifier_max_file_bytes', '12582912')));

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

            return [
                'has_face' => true,
                'signature' => $signature,
                'confidence' => (float) ($decoded['confidence'] ?? 0.0),
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
            $distance = $this->hexHammingDistance((string) $cluster['signature'], $signature);
            if ($distance <= self::FACE_CLUSTER_DISTANCE) {
                return $index;
            }
        }

        return -1;
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
     * @return array{lat: float, lng: float}|null
     */
    private function extractGpsCoordinates(File $file): ?array
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
                $maxBytes = max(2 * 1024 * 1024, min(128 * 1024 * 1024, (int) $this->config->getAppValue(Application::APP_ID, 'ml_classifier_max_file_bytes', '12582912')));

                try {
                    while (!feof($handle)) {
                        $chunk = fread($handle, 1024 * 1024);
                        if ($chunk === false) {
                            return [];
                        }
                        $length = strlen($chunk);
                        if ($length === 0) {
                            continue;
                        }
                        $copied += $length;
                        if ($copied > $maxBytes) {
                            return [];
                        }
                        if (fwrite($output, $chunk) === false) {
                            return [];
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
