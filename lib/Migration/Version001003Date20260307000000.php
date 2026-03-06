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
 * Add the file-faces table for cached face-signature extraction.
 *
 * Each row represents one image file that has been scanned for face signatures.
 * Files without detected faces are also stored (has_face = false) so they
 * are not re-scanned on subsequent runs.
 */
class Version001003Date20260307000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('pdd_file_faces')) {
            $table = $schema->createTable('pdd_file_faces');

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
            $table->addColumn('mime_type', Types::STRING, [
                'notnull' => true,
                'length' => 127,
            ]);
            $table->addColumn('has_face', Types::SMALLINT, [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
                'comment' => 'Whether a face was detected (0=no, 1=yes)',
            ]);
            $table->addColumn('face_signature', Types::TEXT, [
                'notnull' => false,
                'default' => null,
                'comment' => 'Hex-encoded face signature from ML worker',
            ]);
            $table->addColumn('face_confidence', Types::FLOAT, [
                'notnull' => false,
                'default' => null,
                'comment' => 'Face detection confidence score (0.0–1.0)',
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
            $table->addUniqueIndex(['user_id', 'file_id'], 'pdd_face_uid_fid_uniq');
            $table->addIndex(['user_id', 'has_face'], 'pdd_face_uid_hasface_idx');
        }

        return $schema;
    }
}
