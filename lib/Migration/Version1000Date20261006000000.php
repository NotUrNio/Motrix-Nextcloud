<?php

declare(strict_types=1);

namespace OCA\Motrix\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20261006000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('motrix_tasks')) {
            $table = $schema->createTable('motrix_tasks');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 11,
            ]);
            $table->addColumn('task_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('target_folder', Types::STRING, [
                'notnull' => false,
                'length' => 1024,
                'default' => '',
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('synced', Types::BOOLEAN, [
                'notnull' => true,
                'default' => false,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['task_id'], 'motrix_task_id_idx');
            $table->addIndex(['user_id'], 'motrix_user_id_idx');

            return $schema;
        }

        return null;
    }
}
