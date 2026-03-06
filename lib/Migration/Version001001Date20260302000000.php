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
 * Add the image classification table.
 */
class Version001001Date20260302000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('pdd_classifications')) {
            $table = $schema->createTable('pdd_classifications');

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
            $table->addColumn('category', Types::STRING, [
                'notnull' => true,
                'length' => 32,
                'comment' => 'Classification category: document, meme, object, nature, family',
            ]);
            $table->addColumn('confidence', Types::FLOAT, [
                'notnull' => true,
                'default' => 0.0,
                'comment' => 'Confidence score 0.0–1.0',
            ]);
            $table->addColumn('indicators', Types::TEXT, [
                'notnull' => false,
                'comment' => 'JSON array of matching rule indicators',
            ]);
            $table->addColumn('classified_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'file_id'], 'pdd_cls_uid_fid_uniq');
            $table->addIndex(['user_id', 'category'], 'pdd_cls_uid_cat_idx');
        }

        return $schema;
    }
}
