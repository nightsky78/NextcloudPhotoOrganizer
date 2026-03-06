<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
    'routes' => [
        // ── Page ────────────────────────────────────────────────────────
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // ── Duplicate groups ────────────────────────────────────────────
        ['name' => 'duplicate_api#index',  'url' => '/api/v1/duplicates',        'verb' => 'GET'],
        ['name' => 'duplicate_api#show',   'url' => '/api/v1/duplicates/{hash}', 'verb' => 'GET'],

        // ── Scanning ────────────────────────────────────────────────────
        ['name' => 'duplicate_api#scan',       'url' => '/api/v1/scan',        'verb' => 'POST'],
        ['name' => 'duplicate_api#scanStatus', 'url' => '/api/v1/scan/status', 'verb' => 'GET'],

        // ── File deletion ───────────────────────────────────────────────
        ['name' => 'duplicate_api#deleteFile', 'url' => '/api/v1/files/{fileId}',   'verb' => 'DELETE'],
        ['name' => 'duplicate_api#bulkDelete', 'url' => '/api/v1/files/bulk-delete', 'verb' => 'POST'],

        // ── Statistics ──────────────────────────────────────────────────
        ['name' => 'duplicate_api#stats', 'url' => '/api/v1/stats', 'verb' => 'GET'],

        // ── Image classification ────────────────────────────────────────
        ['name' => 'classifier_api#classify',       'url' => '/api/v1/classify',                       'verb' => 'POST'],
        ['name' => 'classifier_api#classifyStatus', 'url' => '/api/v1/classify/status',                'verb' => 'GET'],
        ['name' => 'classifier_api#categories',     'url' => '/api/v1/classify/categories',            'verb' => 'GET'],
        ['name' => 'classifier_api#categoryFiles',  'url' => '/api/v1/classify/category/{category}',   'verb' => 'GET'],
        ['name' => 'classifier_api#moveFile',               'url' => '/api/v1/classify/move/{fileId}',  'verb' => 'POST'],
        ['name' => 'classifier_api#deleteClassifiedFile',   'url' => '/api/v1/classify/files/{fileId}', 'verb' => 'DELETE'],

        // ── People & location insights ───────────────────────────────
        ['name' => 'insights_api#peopleClusters',           'url' => '/api/v1/people/clusters',         'verb' => 'GET'],
        ['name' => 'insights_api#locationMarkers',          'url' => '/api/v1/locations/markers',       'verb' => 'GET'],
    ],
];
