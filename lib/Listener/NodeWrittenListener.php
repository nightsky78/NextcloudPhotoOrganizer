<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Listener;

use OCA\PhotoDedup\AppInfo\Application;
use OCA\PhotoDedup\Service\ScannerService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Re-indexes image files whose content has changed.
 *
 * @implements IEventListener<NodeWrittenEvent>
 */
class NodeWrittenListener implements IEventListener
{
    public function __construct(
        private readonly ScannerService $scannerService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!$event instanceof NodeWrittenEvent) {
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

        try {
            // Force re-hash since content has changed
            $this->scannerService->processFile($userId, $node, forceRehash: true);
        } catch (\Throwable $e) {
            $this->logger->error('NodeWrittenListener: failed to re-index file', [
                'path' => $node->getPath(),
                'exception' => $e,
            ]);
        }
    }
}
