<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\AppInfo;

use OCA\PhotoDedup\Listener\NodeCreatedListener;
use OCA\PhotoDedup\Listener\NodeDeletedListener;
use OCA\PhotoDedup\Listener\NodeRenamedListener;
use OCA\PhotoDedup\Listener\NodeWrittenListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'photodedup';

    /** MIME types considered for duplicate detection. */
    public const SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/heic',
        'image/heif',
        'image/tiff',
        'image/bmp',
        'image/svg+xml',
        'image/x-dcraw', // RAW formats
    ];

    public function __construct()
    {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void
    {
        // File-event listeners for real-time index updates
        $context->registerEventListener(NodeCreatedEvent::class, NodeCreatedListener::class);
        $context->registerEventListener(NodeWrittenEvent::class, NodeWrittenListener::class);
        $context->registerEventListener(NodeDeletedEvent::class, NodeDeletedListener::class);
        $context->registerEventListener(NodeRenamedEvent::class, NodeRenamedListener::class);
    }

    public function boot(IBootContext $context): void
    {
        // Nothing to do at boot time for now.
    }
}
