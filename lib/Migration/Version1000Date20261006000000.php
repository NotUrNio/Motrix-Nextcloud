<?php

declare(strict_types=1);

namespace OCA\NdDownloader\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20261006000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('nddownloader_tasks')) {
            $table = $schema->createTable('nddownloader_tasks');
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
            $table->addUniqueIndex(['task_id'], 'nd_task_id_idx');
            $table->addIndex(['user_id'], 'nd_user_id_idx');

            return $schema;
        }

        return null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        try {
            /** @var \OCP\IDBConnection $connection */
            $connection = \OC::$server->get(\OCP\IDBConnection::class);
            try {
                $connection->executeStatement(
                    'INSERT IGNORE INTO `*PREFIX*nddownloader_tasks` (`task_id`, `user_id`, `target_folder`, `created_at`, `synced`) ' .
                    'SELECT `task_id`, `user_id`, `target_folder`, `created_at`, `synced` FROM `*PREFIX*motrix_tasks`'
                );
            } catch (\Throwable $e) {
                // Table motrix_tasks might not exist, ignore
            }
            /** @var \OCP\IConfig $config */
            $config = \OC::$server->get(\OCP\IConfig::class);

            $token = $config->getAppValue('nddownloader', 'nddownloader_token', '');
            if (empty($token)) {
                $token = $config->getAppValue('nddownloader', 'motrix_token', (string)$config->getAppValue('motrix', 'motrix_token', ''));
                if (empty($token)) {
                    $token = '6wiYws5ONfV1fg3DAwP1tXiFlOmIc1QWW8RuLKY0tbE';
                }
                $config->setAppValue('nddownloader', 'nddownloader_token', $token);
            }

            $endpoint = $config->getAppValue('nddownloader', 'nddownloader_endpoint', '');
            if (empty($endpoint) || $endpoint === 'http://motrix-server:16801') {
                $endpoint = $config->getAppValue('nddownloader', 'motrix_endpoint', (string)$config->getAppValue('motrix', 'motrix_endpoint', 'http://nd-server:16801'));
                if (empty($endpoint) || $endpoint === 'http://motrix-server:16801') {
                    $endpoint = 'http://nd-server:16801';
                }
                $config->setAppValue('nddownloader', 'nddownloader_endpoint', $endpoint);
            }

            $saveDir = $config->getAppValue('nddownloader', 'nddownloader_save_dir', '');
            if (empty($saveDir)) {
                $saveDir = $config->getAppValue('nddownloader', 'motrix_save_dir', (string)$config->getAppValue('motrix', 'motrix_save_dir', '/downloads'));
                if (empty($saveDir)) {
                    $saveDir = '/downloads';
                }
                $config->setAppValue('nddownloader', 'nddownloader_save_dir', $saveDir);
            }
        } catch (\Throwable $e) {
            // Non-fatal if config is unavailable during initial migration runner setup
        }
    }
}
