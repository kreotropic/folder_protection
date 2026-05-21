<?php

declare(strict_types=1);

namespace OCA\FolderProtection\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * "Version 2" canonical migration used by branch "clean".
 *
 * This single step replaces the earlier numbered migrations (1–4) and
 * always creates the table with the full production schema. New installs
 * only ever run this file; existing installations can be cleaned by
 * removing the old rows from `oc_migrations` or allowing them to remain
 * once the database has been reset.
 */
class Version002000000Date20260220000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('folder_protection')) {
            $table = $schema->createTable('folder_protection');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 20,
            ]);

            $table->addColumn('path', Types::STRING, [
                'notnull' => true,
                'length' => 4000,
            ]);

            // MD5 of the normalised path — used as the unique index key.
            // VARCHAR(4000) cannot be indexed on MySQL/MariaDB (InnoDB limit is
            // 3072 bytes with utf8mb4); path_hash sidesteps that constraint.
            $table->addColumn('path_hash', Types::STRING, [
                'notnull' => true,
                'length' => 32,
                'default' => '',
            ]);

            $table->addColumn('file_id', Types::BIGINT, [
                'notnull' => false,
            ]);

            $table->addColumn('user_id', Types::STRING, [
                'notnull' => false,
                'length' => 64,
                'default' => null,
            ]);

            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->addColumn('created_by', Types::STRING, [
                'notnull' => false,
                'length' => 64,
                'default' => null,
            ]);

            $table->addColumn('reason', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['path_hash'], 'fp_path_hash_idx');
            $table->addIndex(['file_id'], 'fp_file_id_idx');
            $table->addIndex(['created_at'], 'fp_created_idx');

            $output->info('Created folder_protection table (version 2 schema)');
        }

        return $schema;
    }
}
