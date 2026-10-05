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

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);

            // Ensure destination folder exists
            $targetSubfolder = trim($targetSubfolder, '/');
            if (!$userFolder->nodeExists($targetSubfolder)) {
                $userFolder->newFolder($targetSubfolder);
            }

            $destFolder = $userFolder->get($targetSubfolder);
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
}
