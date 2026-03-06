<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Listener;

use OCA\PhotoDedup\AppInfo\Application;
use OCA\PhotoDedup\Db\FileClassificationMapper;
use OCA\PhotoDedup\Service\FileHashService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Removes index records when files are deleted.
 *
 * @implements IEventListener<NodeDeletedEvent>
 */
class NodeDeletedListener implements IEventListener
{
    public function __construct(
        private readonly FileHashService $fileHashService,
        private readonly FileClassificationMapper $classificationMapper,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!$event instanceof NodeDeletedEvent) {
            return;
        }

        $node = $event->getNode();
        if (!$node instanceof File) {
            return;
        }

        if (!in_array($node->getMimeType(), Application::SUPPORTED_MIME_TYPES, true)) {
            return;
        }

        $owner = $node->getOwner();
        if ($owner === null) {
            return;
        }

        $userId = $owner->getUID();
        $fileId = $node->getId();

        if ($fileId === null) {
            return;
        }

        try {
            $this->fileHashService->deleteByFileId($userId, $fileId);
            $this->classificationMapper->deleteByFileId($userId, $fileId);
        } catch (\Throwable $e) {
            $this->logger->error('NodeDeletedListener: failed to remove index entry', [
                'fileId' => $fileId,
                'path' => $node->getPath(),
                'exception' => $e,
            ]);
        }
    }
}
