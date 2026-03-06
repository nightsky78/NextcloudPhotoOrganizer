<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Johannes
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\PhotoDedup\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial schema — creates the file-hash index table.
 */
class Version001000Date20260301000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('photodedup_file_hashes')) {
            $table = $schema->createTable('photodedup_file_hashes');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('file_id', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('file_path', Types::TEXT, [
                'notnull' => true,
            ]);
            $table->addColumn('file_size', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('content_hash', Types::STRING, [
                'notnull' => true,
                'length' => 64, // SHA-256 hex digest
            ]);
            $table->addColumn('mime_type', Types::STRING, [
                'notnull' => true,
                'length' => 127,
            ]);
            $table->addColumn('file_mtime', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
                'comment' => 'File modification timestamp for change detection',
            ]);
            $table->addColumn('scanned_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);

            // Fast lookup by user + file
            $table->addUniqueIndex(['user_id', 'file_id'], 'pdd_uid_fid_uniq');

            // Fast duplicate-group queries
            $table->addIndex(['user_id', 'content_hash'], 'pdd_uid_hash_idx');

            // Pre-filter candidates by size before hashing
            $table->addIndex(['user_id', 'file_size'], 'pdd_uid_size_idx');
        }

        return $schema;
    }
}
