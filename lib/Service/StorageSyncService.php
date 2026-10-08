<?php

declare(strict_types=1);

namespace OCA\NdDownloader\Service;

use InvalidArgumentException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

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
     * Sanitizes a user-supplied target folder path to prevent path traversal.
     * Enforces strict segment tokenization and rejects '..' or null bytes.
     */
    public function sanitizeTargetFolder(?string $targetFolder): string {
        if ($targetFolder === null || trim($targetFolder) === '') {
            return 'Downloads';
        }

        if (str_contains($targetFolder, "\0")) {
            throw new InvalidArgumentException('Invalid path: null byte detected');
        }

        $normalized = str_replace('\\', '/', $targetFolder);
        $segments = explode('/', trim($normalized, '/'));
        $cleanSegments = [];

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..' || str_contains($segment, "\0")) {
                throw new InvalidArgumentException('Invalid path: path traversal detected');
            }
            if (preg_match('/[\/\\\\\x00-\x1F\x7F]/', $segment)) {
                throw new InvalidArgumentException('Invalid characters in target path segment');
            }
            $cleanSegments[] = $segment;
        }

        return !empty($cleanSegments) ? implode('/', $cleanSegments) : 'Downloads';
    }

    /**
     * Sanitizes a base filename, removing control characters and path delimiters.
     */
    public function sanitizeFileName(string $filename): string {
        $clean = basename(str_replace('\\', '/', $filename));
        $clean = preg_replace('/[\/\\\\\x00-\x1F\x7F]/', '', $clean);
        $clean = trim((string)$clean);
        if ($clean === '' || $clean === '.' || $clean === '..') {
            return 'download_' . time();
        }
        return $clean;
    }

    /**
     * Maps ND Downloader container download paths to local mount points if needed.
     */
    public function mapPath(string $path): string {
        if (file_exists($path)) {
            return $path;
        }

        $baseNdSaveDir = rtrim((string)$this->config->getAppValue('nddownloader', 'nddownloader_save_dir', '/downloads'), '/');
        if ($baseNdSaveDir === '') {
            $baseNdSaveDir = '/downloads';
        }

        if (str_starts_with($path, $baseNdSaveDir)) {
            $rel = substr($path, strlen($baseNdSaveDir));
            $fallback = '/downloads/' . ltrim($rel, '/');
            if (file_exists($fallback)) {
                return $fallback;
            }
        }

        return $path;
    }

    /**
     * Translates paths reported by the ND Downloader container into canonical local paths,
     * verifying that the canonical realpath is within the allowed download root mount.
     */
    public function resolveLocalPath(?string $path): ?string {
        if (empty($path)) {
            return null;
        }

        $candidate = $this->mapPath($path);
        if (!file_exists($candidate)) {
            return null;
        }

        $realPath = realpath($candidate);
        if ($realPath === false) {
            return null;
        }

        $baseNdSaveDir = rtrim((string)$this->config->getAppValue('nddownloader', 'nddownloader_save_dir', '/downloads'), '/');
        if ($baseNdSaveDir === '') {
            $baseNdSaveDir = '/downloads';
        }

        $allowedRoots = [];
        $realNdDir = realpath($baseNdSaveDir);
        if ($realNdDir !== false) {
            $allowedRoots[] = $realNdDir;
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
            $this->logger->warning('Rejected file outside ND Downloader download root: ' . $realPath, [
                'app' => 'nddownloader',
                'path' => $path,
            ]);
            return null;
        }

        return $realPath;
    }

    /**
     * Syncs a completed ND Downloader download into the user's Nextcloud storage
     * strictly through Nextcloud's Virtual Filesystem APIs (IRootFolder / IUserFolder).
     * Works seamlessly across local disk, Amazon S3, MinIO, and external storage.
     *
     * @param string $userId Nextcloud user UID
     * @param array $task Task dictionary reported by ND Downloader
     * @param string $targetSubfolder Relative subfolder in user storage (e.g. 'Downloads')
     * @return array Result information
     */
    public function syncCompletedTask(string $userId, array $task, string $targetSubfolder = ''): array {
        $taskName = $task['name'] ?? 'download';
        $finalPath = $task['finalPath'] ?? null;

        $ndPath = $finalPath;
        if (empty($ndPath)) {
            $saveDir = $task['saveDir'] ?? '/downloads';
            $ndPath = rtrim($saveDir, '/') . '/' . $taskName;
        }

        $resolvedPath = $this->resolveLocalPath($ndPath);

        if ($resolvedPath === null && !empty($task['files']) && is_array($task['files'])) {
            foreach ($task['files'] as $f) {
                $fPath = is_array($f) ? ($f['path'] ?? null) : null;
                if (!empty($fPath)) {
                    $resolvedCandidate = $this->resolveLocalPath($fPath);
                    if ($resolvedCandidate !== null) {
                        $resolvedPath = $resolvedCandidate;
                        $ndPath = $fPath;
                        break;
                    }
                }
            }
        }

        if ($resolvedPath === null || !file_exists($resolvedPath)) {
            $effectiveNdPath = (string)($ndPath ?: $taskName);
            $mappedPath = $this->mapPath($effectiveNdPath);
            $message = "Completed file not found. ND Downloader path: {$effectiveNdPath}, mapped path: {$mappedPath}";
            $this->logger->warning($message, [
                'app' => 'nddownloader',
                'user' => $userId,
                'task' => $task['id'] ?? $task['taskId'] ?? 'unknown',
            ]);
            return [
                'synced' => false,
                'message' => $message,
            ];
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $cleanTarget = $this->sanitizeTargetFolder($targetSubfolder);

            // Traverse and ensure target folders inside user virtual filesystem
            $destFolder = $userFolder;
            if ($cleanTarget !== '') {
                $segments = explode('/', $cleanTarget);
                foreach ($segments as $seg) {
                    if (!$destFolder->nodeExists($seg)) {
                        $destFolder = $destFolder->newFolder($seg);
                    } else {
                        $node = $destFolder->get($seg);
                        if (!($node instanceof Folder)) {
                            throw new RuntimeException("Target path component '{$seg}' is not a folder");
                        }
                        $destFolder = $node;
                    }
                }
            }

            $fileName = $this->sanitizeFileName(basename($resolvedPath));

            // Collision-safe naming within the target virtual folder
            $destName = $fileName;
            $counter = 1;
            while ($destFolder->nodeExists($destName)) {
                $info = pathinfo($fileName);
                $ext = isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '';
                $destName = $info['filename'] . " ($counter)" . $ext;
                $counter++;
            }

            if (is_file($resolvedPath)) {
                set_time_limit(0);
                $stream = fopen($resolvedPath, 'rb');
                if ($stream === false) {
                    throw new RuntimeException("Failed to open source download for reading: {$resolvedPath}");
                }
                try {
                    $destFile = $destFolder->newFile($destName);
                    $destFile->setContent($stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                @unlink($resolvedPath);
            } elseif (is_dir($resolvedPath)) {
                $newFolder = $destFolder->newFolder($destName);
                $this->copyDirectoryToNextcloud($resolvedPath, $newFolder);
                $this->removeDirectory($resolvedPath);
            }

            // Rescan folder via Nextcloud VFS scanner to ensure instant visibility
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
            $this->logger->error('Failed to sync completed ND Downloader download to Nextcloud: ' . $e->getMessage(), [
                'app' => 'nddownloader',
                'user' => $userId,
                'task' => $task['id'] ?? 'unknown',
            ]);

            return [
                'synced' => false,
                'message' => $e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Streams an entire directory into Nextcloud Virtual Filesystem.
     */
    private function copyDirectoryToNextcloud(string $srcDir, Folder $destFolder): void {
        $items = scandir($srcDir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $srcDir . DIRECTORY_SEPARATOR . $item;
            $safeItemName = $this->sanitizeFileName($item);

            if (is_dir($itemPath)) {
                $subFolder = $destFolder->nodeExists($safeItemName)
                    ? $destFolder->get($safeItemName)
                    : $destFolder->newFolder($safeItemName);
                if ($subFolder instanceof Folder) {
                    $this->copyDirectoryToNextcloud($itemPath, $subFolder);
                }
            } elseif (is_file($itemPath)) {
                $safeFileName = $safeItemName;
                $counter = 1;
                while ($destFolder->nodeExists($safeFileName)) {
                    $info = pathinfo($safeItemName);
                    $ext = isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '';
                    $safeFileName = $info['filename'] . " ($counter)" . $ext;
                    $counter++;
                }

                $stream = fopen($itemPath, 'rb');
                if ($stream !== false) {
                    try {
                        $fileNode = $destFolder->newFile($safeFileName);
                        $fileNode->setContent($stream);
                    } finally {
                        fclose($stream);
                    }
                }
            }
        }
    }

    /**
     * Recursively deletes an unlinked staging directory.
     */
    private function removeDirectory(string $dir): void {
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
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
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
                'app' => 'nddownloader',
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
