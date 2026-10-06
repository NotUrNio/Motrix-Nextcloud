<?php

declare(strict_types=1);

namespace OCA\Motrix\Service;

use OCP\Files\IRootFolder;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class StorageSyncService {
    private IRootFolder $rootFolder;
    private LoggerInterface $logger;
    private IConfig $config;
    private TaskOwnershipService $taskOwnershipService;

    public function __construct(
        IRootFolder $rootFolder,
        LoggerInterface $logger,
        IConfig $config,
        TaskOwnershipService $taskOwnershipService
    ) {
        $this->rootFolder = $rootFolder;
        $this->logger = $logger;
        $this->config = $config;
        $this->taskOwnershipService = $taskOwnershipService;
    }

    /**
     * Translates paths reported by the Motrix container into real local paths in Nextcloud,
     * verifying that the canonical realpath is within the Motrix download root.
     */
    public function resolveLocalPath(?string $path): ?string {
        if (empty($path)) {
            return null;
        }

        $dataDir = rtrim((string)$this->config->getSystemValue('datadirectory', '/home/container/nextcloud/data'), '/');
        $realDataDir = realpath($dataDir);
        if ($realDataDir === false) {
            return null;
        }

        $baseMotrixSaveDir = rtrim((string)$this->config->getAppValue('motrix', 'motrix_save_dir', '/downloads'), '/');
        if (empty($baseMotrixSaveDir)) {
            $baseMotrixSaveDir = '/downloads';
        }

        $candidate = null;

        if (file_exists($path)) {
            $candidate = $path;
        } elseif (str_starts_with($path, $baseMotrixSaveDir)) {
            $rel = substr($path, strlen($baseMotrixSaveDir));
            $candidate = $dataDir . '/' . ltrim($rel, '/');
        } elseif (str_starts_with($path, '/downloads')) {
            $rel = substr($path, strlen('/downloads'));
            $candidate = $dataDir . '/' . ltrim($rel, '/');
        } else {
            foreach (['/var/www/html/data', '/home/container/nextcloud/data'] as $altData) {
                if (str_starts_with($path, $altData)) {
                    $rel = substr($path, strlen($altData));
                    $candidate = $dataDir . '/' . ltrim($rel, '/');
                    break;
                }
            }
        }

        if ($candidate === null || !file_exists($candidate)) {
            return null;
        }

        $realPath = realpath($candidate);
        if ($realPath === false) {
            return null;
        }

        $allowedRoots = [];
        if ($realDataDir !== false) {
            $allowedRoots[] = $realDataDir;
        }
        $realMotrixDir = realpath($baseMotrixSaveDir);
        if ($realMotrixDir !== false) {
            $allowedRoots[] = $realMotrixDir;
        }
        $realDownloads = realpath('/downloads');
        if ($realDownloads !== false) {
            $allowedRoots[] = $realDownloads;
        }

        $isInsideAllowed = false;
        foreach ($allowedRoots as $allowedRoot) {
            if (str_starts_with($realPath, $allowedRoot . DIRECTORY_SEPARATOR) || $realPath === $allowedRoot) {
                $isInsideAllowed = true;
                break;
            }
        }

        if (!$isInsideAllowed) {
            $this->logger->warning('Rejected file outside Motrix download root: ' . $realPath, [
                'app' => 'motrix',
                'path' => $path,
            ]);
            return null;
        }

        return $realPath;
    }

    /**
     * Syncs a completed Motrix download into the user's Nextcloud storage.
     *
     * @param string $userId
     * @param array $task Motrix task dictionary
     * @param string $targetSubfolder Relative subfolder in user storage (default 'Downloads')
     * @return array Result information
     */
    public function syncCompletedTask(string $userId, array $task, string $targetSubfolder = ''): array {
        $taskName = $task['name'] ?? 'download';
        $finalPath = $task['finalPath'] ?? null;

        $resolvedPath = $this->resolveLocalPath($finalPath);

        if ($resolvedPath === null) {
            $saveDir = $task['saveDir'] ?? '/downloads';
            $potentialPath = rtrim($saveDir, '/') . '/' . $taskName;
            $resolvedPath = $this->resolveLocalPath($potentialPath);
        }

        if ($resolvedPath === null && !empty($task['files']) && is_array($task['files'])) {
            foreach ($task['files'] as $f) {
                $fPath = is_array($f) ? ($f['path'] ?? null) : null;
                if (!empty($fPath)) {
                    $resolvedPath = $this->resolveLocalPath($fPath);
                    if ($resolvedPath !== null) {
                        break;
                    }
                }
            }
        }

        if ($resolvedPath === null || !file_exists($resolvedPath)) {
            return [
                'synced' => false,
                'message' => 'Completed file not found on filesystem at: ' . ($finalPath ?: $taskName),
            ];
        }

        $dataDir = rtrim((string)$this->config->getSystemValue('datadirectory', '/home/container/nextcloud/data'), '/');
        $realUserDataDir = realpath($dataDir . '/' . $userId . '/files');

        // Check if the file is inside the user's personal storage directory (direct zero-copy mode)
        if ($realUserDataDir !== false && (str_starts_with($resolvedPath, $realUserDataDir . DIRECTORY_SEPARATOR) || $resolvedPath === $realUserDataDir)) {
            $relInUser = trim(substr($resolvedPath, strlen($realUserDataDir)), DIRECTORY_SEPARATOR);
            $dirInUser = trim(str_replace('\\', '/', dirname($relInUser)), '/.');
            if ($dirInUser === '.') {
                $dirInUser = '';
            }
            $fileName = basename($relInUser);

            $scanResult = $this->scanPath($userId, $dirInUser);

            $taskId = $task['id'] ?? $task['taskId'] ?? null;
            if (!empty($taskId)) {
                $this->taskOwnershipService->markTaskSynced((string)$taskId);
            }

            return [
                'synced' => true,
                'direct' => true,
                'folder' => $dirInUser,
                'destination' => ($dirInUser !== '' ? $dirInUser . '/' : '') . $fileName,
                'fileName' => $fileName,
                'scan' => $scanResult,
            ];
        }

        $cleanTarget = trim(str_replace('\\', '/', $targetSubfolder), '/');
        if (str_contains($cleanTarget, '..') || str_contains($cleanTarget, "\0")) {
            $cleanTarget = 'Downloads';
        }
        if ($cleanTarget === '') {
            $cleanTarget = 'Downloads';
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);

            // Ensure destination folder exists
            if ($cleanTarget !== '' && !$userFolder->nodeExists($cleanTarget)) {
                $userFolder->newFolder($cleanTarget);
            }

            $destFolder = $cleanTarget !== '' ? $userFolder->get($cleanTarget) : $userFolder;
            $fileName = basename($resolvedPath);

            // Avoid collisions
            $destName = $fileName;
            $counter = 1;
            while ($destFolder->nodeExists($destName)) {
                $info = pathinfo($fileName);
                $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
                $destName = $info['filename'] . " ($counter)" . $ext;
                $counter++;
            }

            // Write or copy stream into Nextcloud storage
            if (is_file($resolvedPath)) {
                $destFile = $destFolder->newFile($destName);
                $stream = fopen($resolvedPath, 'rb');
                if ($stream !== false) {
                    $destFile->setContent($stream);
                    fclose($stream);
                }
            } elseif (is_dir($resolvedPath)) {
                $this->copyDirectoryToNextcloud($resolvedPath, $destFolder->newFolder($destName));
            }

            $this->scanPath($userId, $cleanTarget);

            $taskId = $task['id'] ?? $task['taskId'] ?? null;
            if (!empty($taskId)) {
                $this->taskOwnershipService->markTaskSynced((string)$taskId);
            }

            return [
                'synced' => true,
                'direct' => false,
                'folder' => $cleanTarget,
                'destination' => ($cleanTarget !== '' ? $cleanTarget . '/' : '') . $destName,
                'fileName' => $destName,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync completed Motrix download to Nextcloud: ' . $e->getMessage(), [
                'app' => 'motrix',
                'user' => $userId,
                'task' => $task['id'] ?? 'unknown',
            ]);

            return [
                'synced' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function copyDirectoryToNextcloud(string $srcDir, \OCP\Files\Folder $destFolder): void {
        $files = scandir($srcDir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $srcPath = $srcDir . '/' . $file;
            if (is_dir($srcPath)) {
                $newSub = $destFolder->newFolder($file);
                $this->copyDirectoryToNextcloud($srcPath, $newSub);
            } elseif (is_file($srcPath)) {
                $newFile = $destFolder->newFile($file);
                $stream = fopen($srcPath, 'rb');
                if ($stream !== false) {
                    $newFile->setContent($stream);
                    fclose($stream);
                }
            }
        }
    }

    /**
     * Rescans a folder or user storage in Nextcloud so files appear immediately in the UI.
     *
     * @param string $userId Nextcloud user ID
     * @param string $targetFolder Relative folder inside user storage (e.g. 'Movies' or '')
     * @return array
     */
    public function scanPath(string $userId, string $targetFolder = ''): array {
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $cleanFolder = trim(str_replace('\\', '/', $targetFolder), '/');

            if ($cleanFolder !== '' && !$userFolder->nodeExists($cleanFolder)) {
                $storage = $userFolder->getStorage();
                $storage->getScanner()->scan($userFolder->getInternalPath());
            }

            if ($cleanFolder !== '' && $userFolder->nodeExists($cleanFolder)) {
                $node = $userFolder->get($cleanFolder);
            } else {
                $node = $userFolder;
            }

            $storage = $node->getStorage();
            $internalPath = $node->getInternalPath();
            $scanner = $storage->getScanner();
            $scanner->scan($internalPath);

            return [
                'success' => true,
                'path' => $cleanFolder,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to scan Nextcloud storage path: ' . $e->getMessage(), [
                'app' => 'motrix',
                'user' => $userId,
                'folder' => $targetFolder,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
