<?php

declare(strict_types=1);

namespace OCA\FolderProtection\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds path_hash column and replaces the broken unique index on path.
 *
 * The original migration indexed VARCHAR(4000) directly, which exceeds
 * MySQL/MariaDB InnoDB's 3072-byte key limit and caused installation to fail
 * with SQLSTATE[42000] error 1071. This migration fixes existing installs by:
 *   1. Adding path_hash CHAR(32) if absent.
 *   2. Dropping fp_path_idx (the broken index) if it still exists.
 *   3. Adding fp_path_hash_idx (unique on path_hash) if absent.
 *   4. Back-filling path_hash for any rows that have an empty value.
 */
class Version002001000Date20260521000000 extends SimpleMigrationStep {

    public function __construct(private IDBConnection $connection) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('folder_protection')) {
            return null;
        }

        $table = $schema->getTable('folder_protection');
        $changed = false;

        if (!$table->hasColumn('path_hash')) {
            $table->addColumn('path_hash', Types::STRING, [
                'notnull' => true,
                'length'  => 32,
                'default' => '',
            ]);
            $changed = true;
        }

        if ($table->hasIndex('fp_path_idx')) {
            $table->dropIndex('fp_path_idx');
            $changed = true;
        }

        if (!$table->hasIndex('fp_path_hash_idx')) {
            $table->addUniqueIndex(['path_hash'], 'fp_path_hash_idx');
            $changed = true;
        }

        return $changed ? $schema : null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('id', 'path')
           ->from('folder_protection')
           ->where($qb->expr()->eq('path_hash', $qb->createNamedParameter('')));

        $result = $qb->executeQuery();
        $count = 0;

        while ($row = $result->fetchAssociative()) {
            $trimmed = trim((string)$row['path'], '/');
            $normalized = $trimmed === '' ? '/' : '/' . $trimmed;

            $upd = $this->connection->getQueryBuilder();
            $upd->update('folder_protection')
                ->set('path_hash', $upd->createNamedParameter(md5($normalized)))
                ->where($upd->expr()->eq('id', $upd->createNamedParameter((int)$row['id'])));
            $upd->executeStatement();
            $count++;
        }
        $result->closeCursor();

        if ($count > 0) {
            $output->info("folder_protection: back-filled path_hash for $count row(s)");
        }
    }
}
