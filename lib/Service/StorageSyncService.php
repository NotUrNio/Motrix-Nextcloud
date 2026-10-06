<?php

declare(strict_types=1);

namespace OCA\Motrix\Service;

use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class StorageSyncService {
    private IRootFolder $rootFolder;
    private LoggerInterface $logger;
    private IConfig $config;

    public function __construct(
        IRootFolder $rootFolder,
        LoggerInterface $logger,
        IConfig $config
    ) {
        $this->rootFolder = $rootFolder;
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * Translates paths reported by the Motrix container into real local paths in Nextcloud.
     */
    public function resolveLocalPath(?string $path): ?string {
        if (empty($path)) {
            return null;
        }

        if (file_exists($path)) {
            return $path;
        }

        $dataDir = rtrim((string)$this->config->getSystemValue('datadirectory', '/home/container/nextcloud/data'), '/');
        $baseMotrixSaveDir = rtrim((string)$this->config->getAppValue('motrix', 'motrix_save_dir', '/downloads'), '/');
        if (empty($baseMotrixSaveDir)) {
            $baseMotrixSaveDir = '/downloads';
        }

        // Case 1: path starts with motrix_save_dir (e.g. /downloads/...)
        if (str_starts_with($path, $baseMotrixSaveDir)) {
            $rel = substr($path, strlen($baseMotrixSaveDir));
            $candidate = $dataDir . '/' . ltrim($rel, '/');
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        // Case 2: path starts with generic '/downloads'
        if (str_starts_with($path, '/downloads')) {
            $rel = substr($path, strlen('/downloads'));
            $candidate = $dataDir . '/' . ltrim($rel, '/');
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        // Case 3: alternate data paths
        foreach (['/var/www/html/data', '/home/container/nextcloud/data'] as $altData) {
            if (str_starts_with($path, $altData)) {
                $rel = substr($path, strlen($altData));
                $candidate = $dataDir . '/' . ltrim($rel, '/');
                if (file_exists($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
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

        $dataDir = rtrim((string)$this->config->getSystemValue('datadirectory', '/home/container/nextcloud/data'), '/');
        $userStoragePrefix = $dataDir . '/' . $userId . '/files';

        if ($resolvedPath === null) {
            $sub = trim($targetSubfolder, '/');
            $candidates = [
                $userStoragePrefix . ($sub !== '' ? '/' . $sub : '') . '/' . $taskName,
                $userStoragePrefix . '/' . $taskName,
                $userStoragePrefix . '/Movies/' . $taskName,
                $userStoragePrefix . '/Downloads/' . $taskName,
            ];
            foreach ($candidates as $c) {
                if (file_exists($c)) {
                    $resolvedPath = $c;
                    break;
                }
            }
        }

        if ($resolvedPath === null || !file_exists($resolvedPath)) {
            return [
                'synced' => false,
                'message' => 'Completed file not found on filesystem at: ' . ($finalPath ?: $taskName),
            ];
        }

        // Check if the file is already inside the user's Nextcloud storage directory
        if (str_starts_with($resolvedPath, $userStoragePrefix)) {
            $relInUser = trim(substr($resolvedPath, strlen($userStoragePrefix)), '/');
            $dirInUser = trim(dirname($relInUser), '/.');
            if ($dirInUser === '.') {
                $dirInUser = '';
            }
            $fileName = basename($relInUser);

            $scanResult = $this->scanPath($userId, $dirInUser);

            return [
                'synced' => true,
                'direct' => true,
                'folder' => $dirInUser,
                'destination' => ($dirInUser !== '' ? $dirInUser . '/' : '') . $fileName,
                'fileName' => $fileName,
                'scan' => $scanResult,
            ];
        }

        $cleanTarget = trim($targetSubfolder, '/');
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
                // If it's a downloaded folder (e.g. multi-file torrent)
                $this->copyDirectoryToNextcloud($resolvedPath, $destFolder->newFolder($destName));
            }

            $this->scanPath($userId, $cleanTarget);

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
            $cleanFolder = trim($targetFolder, '/');

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
