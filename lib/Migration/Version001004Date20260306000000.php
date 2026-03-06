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

class Version001004Date20260306000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('pdd_face_instances')) {
            $table = $schema->createTable('pdd_face_instances');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('file_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('file_path', Types::TEXT, ['notnull' => true]);
            $table->addColumn('file_size', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('mime_type', Types::STRING, ['notnull' => true, 'length' => 127]);
            $table->addColumn('face_index', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('face_signature', Types::STRING, ['notnull' => true, 'length' => 191]);
            $table->addColumn('face_confidence', Types::FLOAT, ['notnull' => false, 'default' => null]);
            $table->addColumn('file_mtime', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('scanned_at', Types::DATETIME, ['notnull' => true]);

            $table->setPrimaryKey(['id'], 'pdd_face_inst_pk');
            $table->addUniqueIndex(['user_id', 'file_id', 'face_index'], 'pdd_face_inst_uid_fid_idx_uniq');
            $table->addIndex(['user_id', 'file_id'], 'pdd_face_inst_uid_fid_idx');
            $table->addIndex(['user_id', 'face_signature'], 'pdd_face_inst_uid_sig_idx');
        }

        if (!$schema->hasTable('pdd_face_signature_labels')) {
            $table = $schema->createTable('pdd_face_signature_labels');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('face_signature', Types::STRING, ['notnull' => true, 'length' => 191]);
            $table->addColumn('label_name', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);

            $table->setPrimaryKey(['id'], 'pdd_face_label_pk');
            $table->addUniqueIndex(['user_id', 'face_signature'], 'pdd_face_label_uid_sig_uniq');
            $table->addIndex(['user_id'], 'pdd_face_label_uid_idx');
        }

        return $schema;
    }
}
