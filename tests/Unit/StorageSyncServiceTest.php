<?php

declare(strict_types=1);

namespace OCA\NdDownloader\Tests\Unit;

use InvalidArgumentException;
use OCA\NdDownloader\Service\StorageSyncService;
use OCA\NdDownloader\Service\TaskOwnershipService;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class StorageSyncServiceTest {
    private function createService(): StorageSyncService {
        $rootFolder = new class implements IRootFolder {
            public function getUserFolder(string $userId): \OCP\Files\Folder {
                return new \OCP\Files\Folder();
            }
        };

        $logger = new class implements LoggerInterface {
            public function emergency($m, array $c = []): void {}
            public function alert($m, array $c = []): void {}
            public function critical($m, array $c = []): void {}
            public function error($m, array $c = []): void {}
            public function warning($m, array $c = []): void {}
            public function notice($m, array $c = []): void {}
            public function info($m, array $c = []): void {}
            public function debug($m, array $c = []): void {}
            public function log($l, $m, array $c = []): void {}
        };

        $config = new class implements IConfig {
            public function getAppValue(string $app, string $key, $default = null) { return $default; }
            public function setAppValue(string $app, string $key, $value) {}
            public function deleteAppValue(string $app, string $key) {}
            public function getSystemValue(string $key, $default = null) { return $default; }
        };

        $taskOwnership = new class extends TaskOwnershipService {
            public function __construct() {}
        };

        return new StorageSyncService($rootFolder, $logger, $config, $taskOwnership);
    }

    public function testSanitizesValidTargetFolder(): void {
        $service = $this->createService();
        assert($service->sanitizeTargetFolder('Downloads') === 'Downloads');
        assert($service->sanitizeTargetFolder('Movies/Action') === 'Movies/Action');
        assert($service->sanitizeTargetFolder('documents/work/2026') === 'documents/work/2026');
        assert($service->sanitizeTargetFolder('') === 'Downloads');
        assert($service->sanitizeTargetFolder(null) === 'Downloads');
    }

    public function testRejectsPathTraversalInTargetFolder(): void {
        $service = $this->createService();

        $traversals = [
            '../etc',
            'Downloads/../../secret',
            'a/b/../../../root',
            '..',
        ];

        foreach ($traversals as $badPath) {
            try {
                $service->sanitizeTargetFolder($badPath);
                throw new \Exception("Expected traversal rejection for {$badPath}");
            } catch (InvalidArgumentException $e) {
                assert(str_contains($e->getMessage(), 'path traversal'));
            }
        }
    }

    public function testRejectsNullBytesInTargetFolder(): void {
        $service = $this->createService();
        try {
            $service->sanitizeTargetFolder("Downloads\0/secret");
            throw new \Exception('Expected null byte rejection');
        } catch (InvalidArgumentException $e) {
            assert(str_contains($e->getMessage(), 'null byte'));
        }
    }

    public function testSanitizesBaseFilename(): void {
        $service = $this->createService();
        assert($service->sanitizeFileName('ubuntu-24.04.iso') === 'ubuntu-24.04.iso');
        assert($service->sanitizeFileName('../../passwd') === 'passwd');
        assert($service->sanitizeFileName("test\0file.txt") === 'testfile.txt');
        assert(str_starts_with($service->sanitizeFileName(''), 'download_'));
    }
}
