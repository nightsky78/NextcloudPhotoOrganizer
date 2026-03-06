<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Controller;

use OCA\PhotoDedup\AppInfo\Application;
use OCA\PhotoDedup\Service\DuplicateService;
use OCA\PhotoDedup\Service\ScannerService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * REST API for duplicate management.
 */
class DuplicateApiController extends Controller
{
    private const SCOPE_ALL = 'all';
    private const SCOPE_PHOTOS = 'photos';

    public function __construct(
        IRequest $request,
        private readonly DuplicateService $duplicateService,
        private readonly ScannerService $scannerService,
        private readonly LoggerInterface $logger,
        private readonly ?string $userId,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    // ── Duplicate groups ────────────────────────────────────────────

    /**
     * List duplicate groups (paginated).
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $limit = $this->clampInt($this->request->getParam('limit', '50'), 1, 200);
        $offset = $this->clampInt($this->request->getParam('offset', '0'), 0, PHP_INT_MAX);
        $scope = $this->parseScope();

        $data = $this->duplicateService->getDuplicateGroups($this->userId, $limit, $offset, $scope);

        return new JSONResponse($data);
    }

    /**
     * Get a single duplicate group by content hash.
     */
    #[NoAdminRequired]
    public function show(string $hash): JSONResponse
    {
        $scope = $this->parseScope();

        // Validate SHA-256 hex format
        if (!preg_match('/\A[0-9a-f]{64}\z/', $hash)) {
            return new JSONResponse(
                ['error' => 'Invalid hash format.'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $group = $this->duplicateService->getDuplicateGroup($this->userId, $hash, $scope);
        if ($group === null) {
            return new JSONResponse(
                ['error' => 'Duplicate group not found.'],
                Http::STATUS_NOT_FOUND,
            );
        }

        return new JSONResponse($group);
    }

    // ── Scanning ────────────────────────────────────────────────────

    /**
     * Trigger a scan for the current user.
     */
    #[NoAdminRequired]
    public function scan(): JSONResponse
    {
        // Check if a scan is already running
        $progress = $this->scannerService->getProgress($this->userId);
        if ($progress['status'] === 'scanning') {
            return new JSONResponse(
                ['error' => 'A scan is already in progress.', 'progress' => $progress],
                Http::STATUS_CONFLICT,
            );
        }

        $forceRehash = $this->request->getParam('force', 'false') === 'true';

        // Run scan synchronously (for user-triggered scans, this is acceptable
        // as they expect to wait; for very large libraries we'd push to a queue).
        $result = $this->scannerService->scanUser($this->userId, $forceRehash);

        return new JSONResponse($result);
    }

    /**
     * Get current scan status.
     */
    #[NoAdminRequired]
    public function scanStatus(): JSONResponse
    {
        $progress = $this->scannerService->getProgress($this->userId);

        return new JSONResponse($progress);
    }

    // ── File deletion ───────────────────────────────────────────────

    /**
     * Delete a single duplicate file.
     */
    #[NoAdminRequired]
    public function deleteFile(int $fileId): JSONResponse
    {
        if ($fileId <= 0) {
            return new JSONResponse(
                ['error' => 'Invalid file ID.'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $result = $this->duplicateService->deleteFile($this->userId, $fileId);

        $status = $result['success'] ? Http::STATUS_OK : Http::STATUS_CONFLICT;

        return new JSONResponse($result, $status);
    }

    /**
     * Bulk-delete duplicate files.
     *
     * Expects JSON body: { "fileIds": [1, 2, 3] }
     */
    #[NoAdminRequired]
    public function bulkDelete(): JSONResponse
    {
        $fileIds = $this->request->getParam('fileIds', []);

        if (!is_array($fileIds) || empty($fileIds)) {
            return new JSONResponse(
                ['error' => 'fileIds must be a non-empty array.'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        // Sanitize: ensure all IDs are positive integers
        $sanitized = [];
        foreach ($fileIds as $id) {
            $intId = (int) $id;
            if ($intId <= 0) {
                return new JSONResponse(
                    ['error' => "Invalid file ID: {$id}"],
                    Http::STATUS_BAD_REQUEST,
                );
            }
            $sanitized[] = $intId;
        }

        // Safety limit: max 500 deletions per request
        if (count($sanitized) > 500) {
            return new JSONResponse(
                ['error' => 'Too many files. Maximum 500 per request.'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $result = $this->duplicateService->bulkDelete($this->userId, $sanitized);

        return new JSONResponse($result);
    }

    // ── Statistics ──────────────────────────────────────────────────

    #[NoAdminRequired]
    public function stats(): JSONResponse
    {
        $scope = $this->parseScope();
        $stats = $this->duplicateService->getStats($this->userId, $scope);

        return new JSONResponse($stats);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * Parse and clamp an integer parameter.
     */
    private function clampInt(mixed $value, int $min, int $max): int
    {
        $int = (int) $value;

        return max($min, min($max, $int));
    }

    private function parseScope(): string
    {
        $scope = (string) $this->request->getParam('scope', self::SCOPE_ALL);
        return $scope === self::SCOPE_PHOTOS ? self::SCOPE_PHOTOS : self::SCOPE_ALL;
    }
}
