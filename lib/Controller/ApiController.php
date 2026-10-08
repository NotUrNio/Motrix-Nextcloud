<?php

declare(strict_types=1);

namespace OCA\NdDownloader\Controller;

use InvalidArgumentException;
use OCA\NdDownloader\Service\NdDownloaderClient;
use OCA\NdDownloader\Service\StorageSyncService;
use OCA\NdDownloader\Service\TaskOwnershipService;
use OCA\NdDownloader\Service\UrlValidator;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class ApiController extends Controller {
    private NdDownloaderClient $ndClient;
    private StorageSyncService $storageSyncService;
    private TaskOwnershipService $taskOwnershipService;
    private UrlValidator $urlValidator;
    private IUserSession $userSession;
    private IRootFolder $rootFolder;
    private IGroupManager $groupManager;
    private IConfig $config;

    public function __construct(
        string $appName,
        IRequest $request,
        NdDownloaderClient $ndClient,
        StorageSyncService $storageSyncService,
        TaskOwnershipService $taskOwnershipService,
        UrlValidator $urlValidator,
        IUserSession $userSession,
        IRootFolder $rootFolder,
        IGroupManager $groupManager,
        IConfig $config
    ) {
        parent::__construct($appName, $request);
        $this->ndClient = $ndClient;
        $this->storageSyncService = $storageSyncService;
        $this->taskOwnershipService = $taskOwnershipService;
        $this->urlValidator = $urlValidator;
        $this->userSession = $userSession;
        $this->rootFolder = $rootFolder;
        $this->groupManager = $groupManager;
        $this->config = $config;
    }

    /**
     * Normalizes a user-provided target folder path, preventing directory traversal.
     *
     * @throws InvalidArgumentException
     */
    private function normalizeTargetFolder(?string $targetFolder): string {
        if ($targetFolder === null || trim($targetFolder) === '') {
            return '';
        }

        if (str_contains($targetFolder, "\0")) {
            throw new InvalidArgumentException('Invalid path: null bytes detected');
        }

        if (strlen($targetFolder) > 1000) {
            throw new InvalidArgumentException('Invalid path: path length exceeds maximum limit');
        }

        $normalized = str_replace('\\', '/', $targetFolder);
        $segments = explode('/', trim($normalized, '/'));
        $cleanSegments = [];

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new InvalidArgumentException('Invalid path: path traversal detected');
            }
            $cleanSegments[] = $segment;
        }

        return implode('/', $cleanSegments);
    }

    /**
     * Normalizes download task status values into frontend statuses:
     * downloading, queued, paused, completed, error.
     */
    public static function normalizeStatus(?string $status): string {
        $st = strtolower(trim((string)$status));
        return match ($st) {
            'active', 'downloading' => 'downloading',
            'waiting', 'queued' => 'queued',
            'paused', 'stopped' => 'paused',
            'complete', 'completed' => 'completed',
            'error', 'failed' => 'error',
            default => !empty($st) ? $st : 'downloading',
        };
    }

    public function getStatus(): DataResponse {
        try {
            $status = $this->ndClient->getEngineStatus();
            return new DataResponse(['success' => true, 'status' => $status]);
        } catch (\Throwable $e) {
            return new DataResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], Http::STATUS_SERVICE_UNAVAILABLE);
        }
    }

    #[NoAdminRequired]
    public function getStats(): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['success' => false, 'error' => 'User not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $stats = $this->ndClient->getStats();
            return new DataResponse(['success' => true, 'stats' => $stats]);
        } catch (\Throwable $e) {
            return new DataResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], Http::STATUS_SERVICE_UNAVAILABLE);
        }
    }

    #[NoAdminRequired]
    public function getTasks(?string $status = null): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['success' => false, 'error' => 'User not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $userTaskIds = $this->taskOwnershipService->getUserTaskIds($user->getUID());
            $allTasks = $this->ndClient->listTasks($status);

            // Per-user isolation & status normalization
            $tasks = [];
            foreach ($allTasks as $t) {
                $tid = $t['id'] ?? $t['taskId'] ?? $t['gid'] ?? '';
                if (in_array($tid, $userTaskIds, true)) {
                    $t['status'] = self::normalizeStatus($t['status'] ?? '');
                    $tasks[] = $t;
                }
            }

            return new DataResponse(['success' => true, 'tasks' => $tasks]);
        } catch (\Throwable $e) {
            return new DataResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], Http::STATUS_SERVICE_UNAVAILABLE);
        }
    }

    #[NoAdminRequired]
    public function getTask(string $taskId, ?string $targetFolder = null): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['success' => false, 'error' => 'User not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        // Return 404 if not owned by the current user
        if (!$this->taskOwnershipService->isTaskOwnedBy($taskId, $user->getUID())) {
            return new DataResponse(['success' => false, 'error' => 'Task not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $task = $this->ndClient->getTask($taskId);
            if (!$task) {
                return new DataResponse(['success' => false, 'error' => 'Task not found in ND Downloader'], Http::STATUS_NOT_FOUND);
            }

            $rawStatus = (string)($task['status'] ?? '');
            $normalizedStatus = self::normalizeStatus($rawStatus);
            $task['status'] = $normalizedStatus;

            // Auto-rescan folder if task has finished
            if ($normalizedStatus === 'completed') {
                $cleanFolder = $this->normalizeTargetFolder($targetFolder);
                $this->storageSyncService->scanPath($user->getUID(), $cleanFolder);
            }

            return new DataResponse(['success' => true, 'task' => $task]);
        } catch (\Throwable $e) {
            return new DataResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 10, period: 60)]
    public function addTask(
        string $kind = 'url',
        ?string $url = null,
        ?string $magnet = null,
        ?string $torrent = null,
        ?string $filename = null,
        ?string $targetFolder = null
    ): DataResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new DataResponse(['success' => false, 'error' => 'User not logged in'], Http::STATUS_UNAUTHORIZED);
            }
            $userId = $user->getUID();

            // 1. Validate URI / inputs against SSRF
            if ($kind === 'url') {
                if (empty($url)) {
                    return new DataResponse(['success' => false, 'error' => 'URL is required'], Http::STATUS_BAD_REQUEST);
                }
                $this->urlValidator->validate($url);
            } elseif ($kind === 'magnet') {
                if (empty($magnet)) {
                    return new DataResponse(['success' => false, 'error' => 'Magnet URI is required'], Http::STATUS_BAD_REQUEST);
                }
                $this->urlValidator->validate($magnet);
            } elseif ($kind === 'torrent') {
                if (empty($torrent)) {
                    return new DataResponse(['success' => false, 'error' => 'Base64 torrent data is required'], Http::STATUS_BAD_REQUEST);
                }
            } else {
                return new DataResponse(['success' => false, 'error' => "Unsupported task kind: $kind"], Http::STATUS_BAD_REQUEST);
            }

            // 2. Enforce active task limit per user
            $maxActiveTasks = (int)$this->config->getAppValue('nddownloader', 'max_active_tasks_per_user', '5');
            if ($maxActiveTasks > 0) {
                $userTaskIds = $this->taskOwnershipService->getUserTaskIds($userId);
                if (!empty($userTaskIds)) {
                    $allTasks = $this->ndClient->listTasks();
                    $activeCount = 0;
                    foreach ($allTasks as $t) {
                        $tid = $t['id'] ?? $t['taskId'] ?? $t['gid'] ?? '';
                        if (in_array($tid, $userTaskIds, true)) {
                            $st = strtolower((string)($t['status'] ?? ''));
                            if (in_array($st, ['downloading', 'active', 'waiting', 'paused'], true)) {
                                $activeCount++;
                            }
                        }
                    }
                    if ($activeCount >= $maxActiveTasks) {
                        return new DataResponse([
                            'success' => false,
                            'error' => "Active download limit reached ($maxActiveTasks). Please wait for downloads to finish."
                        ], 429);
                    }
                }
            }

            // 3. Resolve destination folder securely using Nextcloud Folder API
            $cleanFolder = $this->normalizeTargetFolder($targetFolder);
            $userFolder = $this->rootFolder->getUserFolder($userId);

            if ($cleanFolder !== '') {
                if (!$userFolder->nodeExists($cleanFolder)) {
                    $folderNode = $userFolder->newFolder($cleanFolder);
                } else {
                    $folderNode = $userFolder->get($cleanFolder);
                }
                if (!($folderNode instanceof Folder)) {
                    throw new InvalidArgumentException('Destination target path is not a directory');
                }
                $internalRelPath = $folderNode->getInternalPath();
            } else {
                $internalRelPath = $userFolder->getInternalPath();
            }

            // Server-side constructed save directory only (never client-controlled)
            $baseSaveDir = rtrim($this->ndClient->getDefaultSaveDir(), '/');
            $saveDir = $baseSaveDir . '/' . $userId . '/' . ltrim($internalRelPath, '/');

            // 4. Send download to ND Downloader server
            if ($kind === 'url') {
                $task = $this->ndClient->addUrl([$url], $saveDir, $filename);
            } elseif ($kind === 'magnet') {
                $task = $this->ndClient->addMagnet($magnet, $saveDir);
            } else {
                $task = $this->ndClient->addTorrent($torrent, $saveDir, $filename);
            }

            // 5. Record task ownership in DB
            $taskId = $task['taskId'] ?? $task['id'] ?? $task['gid'] ?? null;
            if (!empty($taskId)) {
                $this->taskOwnershipService->recordTask((string)$taskId, $userId, $cleanFolder);
            }

            return new DataResponse([
                'success' => true,
                'task' => $task,
                'targetFolder' => $cleanFolder,
            ]);
        } catch (InvalidArgumentException $e) {
            return new DataResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new DataResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function pauseTask(string $taskId): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['success' => false, 'error' => 'User not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        if (!$this->taskOwnershipService->isTaskOwnedBy($taskId, $user->getUID())) {
            return new DataResponse(['success' => false, 'error' => 'Task not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $ok = $this->ndClient->pauseTask($taskId);
            return new DataResponse(['success' => $ok]);
        } catch (\Throwable $e) {
            return new DataResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function resumeTask(string $taskId): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['success' => false, 'error' => 'User not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        if (!$this->taskOwnershipService->isTaskOwnedBy($taskId, $user->getUID())) {
            return new DataResponse(['success' => false, 'error' => 'Task not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $ok = $this->ndClient->resumeTask($taskId);
            return new DataResponse(['success' => $ok]);
        } catch (\Throwable $e) {
            return new DataResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function deleteTask(string $taskId, bool $deleteFiles = false): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['success' => false, 'error' => 'User not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        if (!$this->taskOwnershipService->isTaskOwnedBy($taskId, $user->getUID())) {
            return new DataResponse(['success' => false, 'error' => 'Task not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $ok = $this->ndClient->removeTask($taskId, $deleteFiles);
            if (!$ok) {
                return new DataResponse(['success' => false, 'error' => 'ND Downloader did not remove the task'], Http::STATUS_BAD_GATEWAY);
            }
            $this->taskOwnershipService->deleteTask($taskId);
            return new DataResponse(['success' => true, 'removed' => $ok]);
        } catch (\Throwable $e) {
            return new DataResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function deleteTaskFallback(string $taskId, bool $deleteFiles = false): DataResponse {
        return $this->deleteTask($taskId, $deleteFiles);
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function syncTask(string $taskId, string $targetFolder = ''): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['success' => false, 'error' => 'User not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        $userId = $user->getUID();
        $taskMeta = $this->taskOwnershipService->getTask($taskId);
        if (!$taskMeta || $taskMeta['user_id'] !== $userId) {
            return new DataResponse(['success' => false, 'error' => 'Task not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $task = $this->ndClient->getTask($taskId);
            if (!$task) {
                // Fallback: search task list in case of ID/GID discrepancy
                $allTasks = $this->ndClient->listTasks();
                foreach ($allTasks as $t) {
                    if (($t['id'] ?? '') === $taskId || ($t['gid'] ?? '') === $taskId) {
                        $task = $t;
                        break;
                    }
                }
            }

            if (!$task) {
                return new DataResponse(['success' => false, 'error' => 'Task not found in ND Downloader'], Http::STATUS_NOT_FOUND);
            }

            $effectiveFolder = $targetFolder !== '' ? $targetFolder : ($taskMeta['target_folder'] ?? '');
            $cleanFolder = $this->normalizeTargetFolder($effectiveFolder);

            $result = $this->storageSyncService->syncCompletedTask($userId, $task, $cleanFolder);
            if (empty($result['synced'])) {
                $message = $result['message'] ?? $result['error'] ?? 'Task could not be synced';
                return new DataResponse([
                    'success' => false,
                    'message' => $message,
                    'error' => $message,
                    'result' => $result,
                ], Http::STATUS_UNPROCESSABLE_ENTITY);
            }
            return new DataResponse(['success' => true, 'result' => $result]);
        } catch (\Throwable $e) {
            return new DataResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function scanPath(string $targetFolder = ''): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['success' => false, 'error' => 'User not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $cleanFolder = $this->normalizeTargetFolder($targetFolder);
            $res = $this->storageSyncService->scanPath($user->getUID(), $cleanFolder);
            return new DataResponse(['success' => true, 'result' => $res]);
        } catch (\Throwable $e) {
            return new DataResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function getSettings(): DataResponse {
        $user = $this->userSession->getUser();
        $isAdmin = $user !== null && $this->groupManager->isAdmin($user->getUID());

        return new DataResponse([
            'success' => true,
            'isAdmin' => $isAdmin,
            // Only expose internal endpoint URL and security lists to administrators
            'endpoint' => $isAdmin ? $this->ndClient->getEndpoint() : '',
            'saveDir' => $this->ndClient->getDefaultSaveDir(),
            'hasToken' => !empty($this->ndClient->getToken()),
            'allowPrivateNetwork' => $this->config->getAppValue('nddownloader', UrlValidator::CONFIG_ALLOW_PRIVATE, 'no') === 'yes',
            'domainAllowlist' => $isAdmin ? (string)$this->config->getAppValue('nddownloader', UrlValidator::CONFIG_DOMAIN_ALLOWLIST, '') : '',
            'domainDenylist' => $isAdmin ? (string)$this->config->getAppValue('nddownloader', UrlValidator::CONFIG_DOMAIN_DENYLIST, '') : '',
            'maxActiveTasksPerUser' => (int)$this->config->getAppValue('nddownloader', 'max_active_tasks_per_user', '5'),
        ]);
    }

    public function saveSettings(
        string $endpoint,
        ?string $token = null,
        ?string $saveDir = null,
        ?string $allowPrivateNetwork = null,
        ?string $domainAllowlist = null,
        ?string $domainDenylist = null,
        ?int $maxActiveTasksPerUser = null
    ): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user || !$this->groupManager->isAdmin($user->getUID())) {
            return new DataResponse(['success' => false, 'error' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
        }

        $this->config->setAppValue('nddownloader', NdDownloaderClient::CONFIG_ENDPOINT, rtrim($endpoint, '/'));

        if ($token !== null && trim($token) !== '') {
            $this->ndClient->setToken(trim($token));
        }

        if (!empty($saveDir)) {
            $this->config->setAppValue('nddownloader', NdDownloaderClient::CONFIG_DEFAULT_SAVE_DIR, trim($saveDir));
        }

        if ($allowPrivateNetwork !== null) {
            $val = in_array(strtolower($allowPrivateNetwork), ['yes', 'true', '1'], true) ? 'yes' : 'no';
            $this->config->setAppValue('nddownloader', UrlValidator::CONFIG_ALLOW_PRIVATE, $val);
        }

        if ($domainAllowlist !== null) {
            $this->config->setAppValue('nddownloader', UrlValidator::CONFIG_DOMAIN_ALLOWLIST, trim($domainAllowlist));
        }

        if ($domainDenylist !== null) {
            $this->config->setAppValue('nddownloader', UrlValidator::CONFIG_DOMAIN_DENYLIST, trim($domainDenylist));
        }

        if ($maxActiveTasksPerUser !== null && $maxActiveTasksPerUser >= 0) {
            $this->config->setAppValue('nddownloader', 'max_active_tasks_per_user', (string)$maxActiveTasksPerUser);
        }

        return new DataResponse(['success' => true]);
    }

    #[UserRateLimit(limit: 20, period: 60)]
    public function testConnection(): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user || !$this->groupManager->isAdmin($user->getUID())) {
            return new DataResponse(['success' => false, 'error' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
        }

        $res = $this->ndClient->testConnection();
        return new DataResponse($res);
    }

    #[UserRateLimit(limit: 10, period: 60)]
    public function startEngine(): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user || !$this->groupManager->isAdmin($user->getUID())) {
            return new DataResponse(['success' => false, 'error' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
        }

        try {
            // Optional admin-configured host watchdog trigger file (e.g. for container watchdogs)
            $triggerFile = (string)$this->config->getAppValue('nddownloader', 'start_trigger_path', '');
            if (!empty($triggerFile) && @file_exists(dirname($triggerFile))) {
                @touch($triggerFile);
                sleep(1);
            }

            // Test connection
            $res = $this->ndClient->testConnection();
            if (!empty($res['success'])) {
                $version = $res['engine']['featureReport']['version'] ?? 'ready';
                $state = $res['engine']['state'] ?? 'ready';
                return new DataResponse([
                    'success' => true,
                    'message' => "ND Downloader engine is running and ready (Aria2 {$version})",
                    'version' => $version,
                    'state' => $state,
                    'engine' => $res['engine'] ?? null,
                    'stats' => $res['stats'] ?? null,
                ]);
            }

            // If a trigger was run, wait briefly and retry once
            if (!empty($triggerFile)) {
                sleep(2);
                $retryRes = $this->ndClient->testConnection();
                if (!empty($retryRes['success'])) {
                    $version = $retryRes['engine']['featureReport']['version'] ?? 'ready';
                    $state = $retryRes['engine']['state'] ?? 'ready';
                    return new DataResponse([
                        'success' => true,
                        'message' => "ND Downloader engine started successfully (Aria2 {$version})",
                        'version' => $version,
                        'state' => $state,
                        'engine' => $retryRes['engine'] ?? null,
                        'stats' => $retryRes['stats'] ?? null,
                    ]);
                }
            }

            return new DataResponse([
                'success' => false,
                'error' => $res['error'] ?? 'ND Downloader engine could not be reached',
            ], Http::STATUS_SERVICE_UNAVAILABLE);
        } catch (\Throwable $e) {
            return new DataResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}

