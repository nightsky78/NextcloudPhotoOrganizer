<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Controller;

use OCA\PhotoDedup\AppInfo\Application;
use OCA\PhotoDedup\Service\ClassifierService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * REST API for image classification.
 */
class ClassifierApiController extends Controller
{
    private const SCOPE_ALL = 'all';
    private const SCOPE_PHOTOS = 'photos';

    public function __construct(
        IRequest $request,
        private readonly ClassifierService $classifierService,
        private readonly LoggerInterface $logger,
        private readonly ?string $userId,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    // ── Classification ──────────────────────────────────────────────

    /**
     * Trigger image classification for the current user.
     */
    #[NoAdminRequired]
    public function classify(): JSONResponse
    {
        $progress = $this->classifierService->getProgress($this->userId);
        if ($progress['status'] === 'classifying') {
            return new JSONResponse(
                ['error' => 'Classification already in progress.', 'progress' => $progress],
                Http::STATUS_CONFLICT,
            );
        }

        $force = $this->request->getParam('force', 'false') === 'true';
        $result = $this->classifierService->classifyUser($this->userId, $force);

        return new JSONResponse($result);
    }

    /**
     * Get classification progress.
     */
    #[NoAdminRequired]
    public function classifyStatus(): JSONResponse
    {
        return new JSONResponse($this->classifierService->getProgress($this->userId));
    }

    // ── Category browsing ───────────────────────────────────────────

    /**
     * Get category counts (summary).
     */
    #[NoAdminRequired]
    public function categories(): JSONResponse
    {
        $scope = $this->parseScope();
        return new JSONResponse($this->classifierService->getCategoryCounts($this->userId, $scope));
    }

    /**
     * Get files in a specific category (paginated).
     */
    #[NoAdminRequired]
    public function categoryFiles(string $category): JSONResponse
    {
        if (!in_array($category, ClassifierService::CATEGORIES, true)) {
            return new JSONResponse(
                ['error' => 'Invalid category. Valid: ' . implode(', ', ClassifierService::CATEGORIES)],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $limit = $this->clampInt($this->request->getParam('limit', '50'), 1, 200);
        $offset = $this->clampInt($this->request->getParam('offset', '0'), 0, PHP_INT_MAX);
        $scope = $this->parseScope();

        $data = $this->classifierService->getFilesByCategory($this->userId, $category, $limit, $offset, $scope);

        return new JSONResponse($data);
    }

    // ── File actions ────────────────────────────────────────────────

    /**
     * Move a file to a target folder.
     *
     * Expects JSON body: { "targetFolder": "Photos/Nature" }
     */
    #[NoAdminRequired]
    public function moveFile(int $fileId): JSONResponse
    {
        if ($fileId <= 0) {
            return new JSONResponse(
                ['error' => 'Invalid file ID.'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $targetFolder = $this->request->getParam('targetFolder', '');
        if (empty($targetFolder) || !is_string($targetFolder)) {
            return new JSONResponse(
                ['error' => 'targetFolder is required.'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        // Reject path traversal attempts
        $normalized = str_replace('\\', '/', $targetFolder);
        if (str_contains($normalized, '..') || str_starts_with($normalized, '/')) {
            return new JSONResponse(
                ['error' => 'Invalid target folder path.'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $result = $this->classifierService->moveFile($this->userId, $fileId, $targetFolder);
        $status = $result['success'] ? Http::STATUS_OK : Http::STATUS_CONFLICT;

        return new JSONResponse($result, $status);
    }

    /**
     * Delete a classified file (move to trash).
     */
    #[NoAdminRequired]
    public function deleteClassifiedFile(int $fileId): JSONResponse
    {
        if ($fileId <= 0) {
            return new JSONResponse(
                ['error' => 'Invalid file ID.'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $result = $this->classifierService->deleteFile($this->userId, $fileId);
        $status = $result['success'] ? Http::STATUS_OK : Http::STATUS_CONFLICT;

        return new JSONResponse($result, $status);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function clampInt(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    private function parseScope(): string
    {
        $scope = (string) $this->request->getParam('scope', self::SCOPE_ALL);
        return $scope === self::SCOPE_PHOTOS ? self::SCOPE_PHOTOS : self::SCOPE_ALL;
    }
}
