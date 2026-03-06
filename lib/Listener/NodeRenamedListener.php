<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Listener;

use OCA\PhotoDedup\AppInfo\Application;
use OCA\PhotoDedup\Service\FileHashService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Updates file paths in the index when files are renamed or moved.
 *
 * @implements IEventListener<NodeRenamedEvent>
 */
class NodeRenamedListener implements IEventListener
{
    public function __construct(
        private readonly FileHashService $fileHashService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!$event instanceof NodeRenamedEvent) {
            return;
        }

        $target = $event->getTarget();
        if (!$target instanceof File) {
            return;
        }

        if (!in_array($target->getMimeType(), Application::SUPPORTED_MIME_TYPES, true)) {
            return;
        }

        $owner = $target->getOwner();
        if ($owner === null) {
            return;
        }

        $userId = $owner->getUID();
        $fileId = $target->getId();

        if ($fileId === null) {
            return;
        }

        try {
            $existing = $this->fileHashService->getByFileId($userId, $fileId);
            if ($existing !== null) {
                // Update the stored path — the hash remains the same since content didn't change
                $prefix = "/{$userId}/files/";
                $fullPath = $target->getPath();
                $relativePath = str_starts_with($fullPath, $prefix)
                    ? substr($fullPath, strlen($prefix))
                    : $target->getInternalPath();

                $this->fileHashService->upsert(
                    userId: $userId,
                    fileId: $fileId,
                    filePath: $relativePath,
                    fileSize: $existing->getFileSize(),
                    contentHash: $existing->getContentHash(),
                    mimeType: $existing->getMimeType(),
                    fileMtime: $existing->getFileMtime(),
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('NodeRenamedListener: failed to update path', [
                'fileId' => $fileId,
                'path' => $target->getPath(),
                'exception' => $e,
            ]);
        }
    }
}
