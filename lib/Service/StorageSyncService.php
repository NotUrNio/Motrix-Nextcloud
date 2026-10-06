<?php

declare(strict_types=1);

namespace OCA\Motrix\Service;

use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use Psr\Log\LoggerInterface;

class StorageSyncService {
    private IRootFolder $rootFolder;
    private LoggerInterface $logger;

    public function __construct(
        IRootFolder $rootFolder,
        LoggerInterface $logger
    ) {
        $this->rootFolder = $rootFolder;
        $this->logger = $logger;
    }

    /**
     * Syncs a completed Motrix download into the user's Nextcloud storage.
     *
     * @param string $userId
     * @param array $task Motrix task dictionary
     * @param string $targetSubfolder Relative subfolder in user storage (default 'Downloads')
     * @return array Result information
     */
    public function syncCompletedTask(string $userId, array $task, string $targetSubfolder = 'Downloads'): array {
        $taskName = $task['name'] ?? 'download';
        $finalPath = $task['finalPath'] ?? null;

        if (empty($finalPath) || !file_exists($finalPath)) {
            // Also check saveDir + name if finalPath is not directly populated
            $saveDir = $task['saveDir'] ?? '/downloads';
            $potentialPath = rtrim($saveDir, '/') . '/' . $taskName;
            if (file_exists($potentialPath)) {
                $finalPath = $potentialPath;
            }
        }

        if (empty($finalPath) || !file_exists($finalPath)) {
            return [
                'synced' => false,
                'message' => 'Completed file not found on filesystem at: ' . ($finalPath ?: 'unknown path'),
            ];
        }

        $cleanTarget = trim($targetSubfolder, '/');

        // Check if the file is already inside the user's Nextcloud storage directory
        // Motrix saveDir prefix is /downloads, which maps to Nextcloud's data directory.
        $userStoragePrefix = '/downloads/' . $userId . '/files';
        if (!empty($finalPath) && str_starts_with($finalPath, $userStoragePrefix)) {
            // Already inside user storage! Simply scan Nextcloud filecache.
            $scanResult = $this->scanPath($userId, $cleanTarget);
            return [
                'synced' => true,
                'direct' => true,
                'destination' => ($cleanTarget !== '' ? $cleanTarget . '/' : '') . basename($finalPath),
                'fileName' => basename($finalPath),
                'scan' => $scanResult,
            ];
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);

            // Ensure destination folder exists
            if ($cleanTarget !== '' && !$userFolder->nodeExists($cleanTarget)) {
                $userFolder->newFolder($cleanTarget);
            }

            $destFolder = $cleanTarget !== '' ? $userFolder->get($cleanTarget) : $userFolder;
            $fileName = basename($finalPath);

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
            if (is_file($finalPath)) {
                $destFile = $destFolder->newFile($destName);
                $stream = fopen($finalPath, 'rb');
                if ($stream !== false) {
                    $destFile->setContent($stream);
                    fclose($stream);
                }
            } elseif (is_dir($finalPath)) {
                // If it's a downloaded folder (e.g. multi-file torrent)
                $this->copyDirectoryToNextcloud($finalPath, $destFolder->newFolder($destName));
            }

            return [
                'synced' => true,
                'destination' => $targetSubfolder . '/' . $destName,
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
