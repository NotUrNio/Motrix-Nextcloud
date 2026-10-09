<?php

declare(strict_types=1);

namespace OCA\NdDownloader\Tests\Unit;

use OCA\NdDownloader\Service\StorageSyncService;
use OCA\NdDownloader\Service\TaskOwnershipService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class StorageSyncServiceIntegrationTest {
    private function removeDirRecursive(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function createFixture(): array {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nd_test_staging_' . uniqid('', true);
        mkdir($tempDir, 0777, true);

        $userRoot = new Folder('/');
        $rootFolder = new class($userRoot) implements IRootFolder {
            private Folder $folder;
            public function __construct(Folder $folder) {
                $this->folder = $folder;
            }
            public function getUserFolder(string $userId): Folder {
                return $this->folder;
            }
        };

        $logger = new class implements LoggerInterface {
            public array $logs = [];
            public function emergency($m, array $c = []): void { $this->logs[] = ['emergency', $m]; }
            public function alert($m, array $c = []): void { $this->logs[] = ['alert', $m]; }
            public function critical($m, array $c = []): void { $this->logs[] = ['critical', $m]; }
            public function error($m, array $c = []): void { $this->logs[] = ['error', $m]; }
            public function warning($m, array $c = []): void { $this->logs[] = ['warning', $m]; }
            public function notice($m, array $c = []): void { $this->logs[] = ['notice', $m]; }
            public function info($m, array $c = []): void { $this->logs[] = ['info', $m]; }
            public function debug($m, array $c = []): void { $this->logs[] = ['debug', $m]; }
            public function log($l, $m, array $c = []): void { $this->logs[] = [$l, $m]; }
        };

        $config = new class($tempDir) implements IConfig {
            private string $saveDir;
            public function __construct(string $saveDir) {
                $this->saveDir = $saveDir;
            }
            public function getAppValue(string $app, string $key, $default = null) {
                if ($key === 'nddownloader_save_dir') {
                    return $this->saveDir;
                }
                return $default;
            }
            public function setAppValue(string $app, string $key, $value) {}
            public function deleteAppValue(string $app, string $key) {}
            public function getSystemValue(string $key, $default = null) { return $default; }
        };

        $taskOwnership = new class extends TaskOwnershipService {
            public array $syncedTasks = [];
            public function __construct() {}
            public function markTaskSynced(string $taskId): void {
                $this->syncedTasks[] = $taskId;
            }
        };

        $service = new StorageSyncService($rootFolder, $logger, $config, $taskOwnership);

        return [
            'service' => $service,
            'userRoot' => $userRoot,
            'tempDir' => $tempDir,
            'taskOwnership' => $taskOwnership,
            'logger' => $logger,
        ];
    }

    public function testSyncSingleFileSuccess(): void {
        $fixture = $this->createFixture();
        $service = $fixture['service'];
        $userRoot = $fixture['userRoot'];
        $tempDir = $fixture['tempDir'];
        $taskOwnership = $fixture['taskOwnership'];

        try {
            $filePath = $tempDir . DIRECTORY_SEPARATOR . 'ubuntu-server.iso';
            file_put_contents($filePath, 'ISO-PAYLOAD-BYTES-12345');

            $task = [
                'id' => 'task-ubuntu-1',
                'name' => 'ubuntu-server.iso',
                'finalPath' => $filePath,
            ];

            $result = $service->syncCompletedTask('alice', $task, 'ISOs/Linux');

            assert($result['synced'] === true, 'Task should be successfully synced');
            assert($result['destination'] === 'ISOs/Linux/ubuntu-server.iso', 'Destination path should match');
            assert($userRoot->nodeExists('ISOs'), 'ISOs folder must exist');

            /** @var Folder $isos */
            $isos = $userRoot->get('ISOs');
            assert($isos instanceof Folder);
            assert($isos->nodeExists('Linux'), 'Linux folder must exist');

            /** @var Folder $linux */
            $linux = $isos->get('Linux');
            assert($linux instanceof Folder);
            assert($linux->nodeExists('ubuntu-server.iso'), 'File must exist in VFS');

            /** @var File $file */
            $file = $linux->get('ubuntu-server.iso');
            assert($file instanceof File);
            assert($file->getContent() === 'ISO-PAYLOAD-BYTES-12345', 'VFS file content must match payload');

            assert(!file_exists($filePath), 'Source file must be unlinked after streaming into VFS');
            assert(in_array('task-ubuntu-1', $taskOwnership->syncedTasks, true), 'Task must be marked as synced');
        } finally {
            $this->removeDirRecursive($tempDir);
        }
    }

    public function testSyncFileWithCollisionResolution(): void {
        $fixture = $this->createFixture();
        $service = $fixture['service'];
        $userRoot = $fixture['userRoot'];
        $tempDir = $fixture['tempDir'];

        try {
            $downloadsFolder = $userRoot->newFolder('Downloads');
            $existingFile = $downloadsFolder->newFile('archive.tar.gz');
            $existingFile->setContent('EXISTING-VERSION-1');

            $filePath = $tempDir . DIRECTORY_SEPARATOR . 'archive.tar.gz';
            file_put_contents($filePath, 'NEW-VERSION-2');

            $task = [
                'id' => 'task-collision-1',
                'name' => 'archive.tar.gz',
                'finalPath' => $filePath,
            ];

            $result = $service->syncCompletedTask('bob', $task, 'Downloads');

            assert($result['synced'] === true, 'Sync should succeed');
            assert($result['destination'] === 'Downloads/archive.tar (1).gz', 'Collision should create indexed name');
            assert($result['fileName'] === 'archive.tar (1).gz', 'Reported fileName should be collision-resolved');

            assert($downloadsFolder->nodeExists('archive.tar.gz'), 'Original file must still exist');
            assert($downloadsFolder->nodeExists('archive.tar (1).gz'), 'Collision file must exist');

            /** @var File $orig */
            $orig = $downloadsFolder->get('archive.tar.gz');
            assert($orig->getContent() === 'EXISTING-VERSION-1', 'Original file content must be preserved');

            /** @var File $collided */
            $collided = $downloadsFolder->get('archive.tar.gz');
            $collidedNode = $downloadsFolder->get('archive.tar (1).gz');
            assert($collidedNode instanceof File);
            assert($collidedNode->getContent() === 'NEW-VERSION-2', 'Collided file content must match new upload');

            assert(!file_exists($filePath), 'Temporary file must be removed');
        } finally {
            $this->removeDirRecursive($tempDir);
        }
    }

    public function testRejectsTargetFolderTraversal(): void {
        $fixture = $this->createFixture();
        $service = $fixture['service'];
        $userRoot = $fixture['userRoot'];
        $tempDir = $fixture['tempDir'];

        try {
            $filePath = $tempDir . DIRECTORY_SEPARATOR . 'exploit.sh';
            file_put_contents($filePath, '#!/bin/sh');

            $badTargets = [
                '../../etc',
                'Downloads/../../secret',
                '../..',
                'a/b/../../../root',
            ];

            foreach ($badTargets as $badTarget) {
                $task = [
                    'id' => 'task-traversal-' . uniqid(),
                    'name' => 'exploit.sh',
                    'finalPath' => $filePath,
                ];

                $result = $service->syncCompletedTask('attacker', $task, $badTarget);

                assert($result['synced'] === false, "Traversal target '{$badTarget}' must fail sync");
                assert(
                    str_contains($result['message'], 'path traversal') || str_contains($result['error'] ?? '', 'path traversal'),
                    "Error message must indicate path traversal rejection for '{$badTarget}'"
                );
            }

            assert(!$userRoot->nodeExists('exploit.sh'), 'Exploit file must not be written to root');
        } finally {
            $this->removeDirRecursive($tempDir);
        }
    }

    public function testRejectsTargetFolderNullBytes(): void {
        $fixture = $this->createFixture();
        $service = $fixture['service'];
        $tempDir = $fixture['tempDir'];

        try {
            $filePath = $tempDir . DIRECTORY_SEPARATOR . 'test.txt';
            file_put_contents($filePath, 'safe');

            $task = [
                'id' => 'task-null-1',
                'name' => 'test.txt',
                'finalPath' => $filePath,
            ];

            $result = $service->syncCompletedTask('user', $task, "Downloads\0/secret");

            assert($result['synced'] === false, 'Null byte target must be rejected');
            assert(str_contains($result['message'], 'null byte'), 'Message must indicate null byte detection');
        } finally {
            $this->removeDirRecursive($tempDir);
        }
    }

    public function testRejectsSourcePathOutsideAllowedRoot(): void {
        $fixture = $this->createFixture();
        $service = $fixture['service'];
        $userRoot = $fixture['userRoot'];
        $tempDir = $fixture['tempDir'];

        $outsideDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nd_outside_' . uniqid('', true);
        mkdir($outsideDir, 0777, true);

        try {
            $outsideFile = $outsideDir . DIRECTORY_SEPARATOR . 'sensitive.conf';
            file_put_contents($outsideFile, 'DB_PASSWORD=secret');

            $task = [
                'id' => 'task-outside-1',
                'name' => 'sensitive.conf',
                'finalPath' => $outsideFile,
            ];

            $result = $service->syncCompletedTask('user', $task, 'Downloads');

            assert($result['synced'] === false, 'File outside download root must not be synced');
            assert(file_exists($outsideFile), 'Outside file must not be modified or deleted');
            assert(!$userRoot->nodeExists('Downloads'), 'Nothing should be written to Downloads');
        } finally {
            $this->removeDirRecursive($tempDir);
            $this->removeDirRecursive($outsideDir);
        }
    }

    public function testSyncRecursiveDirectory(): void {
        $fixture = $this->createFixture();
        $service = $fixture['service'];
        $userRoot = $fixture['userRoot'];
        $tempDir = $fixture['tempDir'];

        try {
            $stagingDir = $tempDir . DIRECTORY_SEPARATOR . 'MyDataset';
            mkdir($stagingDir . DIRECTORY_SEPARATOR . 'nested', 0777, true);
            file_put_contents($stagingDir . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'data.csv', 'id,val\n1,100');
            file_put_contents($stagingDir . DIRECTORY_SEPARATOR . 'readme.txt', 'Dataset info');

            $task = [
                'id' => 'task-dir-1',
                'name' => 'MyDataset',
                'finalPath' => $stagingDir,
            ];

            $result = $service->syncCompletedTask('alice', $task, 'Datasets');

            assert($result['synced'] === true, 'Directory sync should succeed');
            assert($userRoot->nodeExists('Datasets'), 'Datasets folder must exist');

            /** @var Folder $datasets */
            $datasets = $userRoot->get('Datasets');
            assert($datasets->nodeExists('MyDataset'), 'MyDataset folder must exist in VFS');

            /** @var Folder $myDataset */
            $myDataset = $datasets->get('MyDataset');
            assert($myDataset->nodeExists('nested'), 'nested subfolder must exist');
            assert($myDataset->nodeExists('readme.txt'), 'readme.txt must exist');

            /** @var Folder $nested */
            $nested = $myDataset->get('nested');
            assert($nested->nodeExists('data.csv'), 'data.csv must exist in nested folder');

            /** @var File $csv */
            $csv = $nested->get('data.csv');
            assert(str_contains($csv->getContent(), 'id,val'), 'CSV content must match');

            assert(!file_exists($stagingDir), 'Staging directory must be removed after sync');
        } finally {
            $this->removeDirRecursive($tempDir);
        }
    }
}
