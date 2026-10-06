<?php

declare(strict_types=1);

namespace OCA\Motrix\Controller;

use InvalidArgumentException;
use OCA\Motrix\Service\MotrixClient;
use OCA\Motrix\Service\StorageSyncService;
use OCA\Motrix\Service\TaskOwnershipService;
use OCA\Motrix\Service\UrlValidator;
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
    private MotrixClient $motrixClient;
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
        MotrixClient $motrixClient,
        StorageSyncService $storageSyncService,
        TaskOwnershipService $taskOwnershipService,
        UrlValidator $urlValidator,
        IUserSession $userSession,
        IRootFolder $rootFolder,
        IGroupManager $groupManager,
        IConfig $config
    ) {
        parent::__construct($appName, $request);
        $this->motrixClient = $motrixClient;
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

    #[NoAdminRequired]
    public function getStatus(): DataResponse {
        try {
            $status = $this->motrixClient->getEngineStatus();
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
        try {
            $stats = $this->motrixClient->getStats();
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
            $allTasks = $this->motrixClient->listTasks($status);

            // Per-user isolation: only return tasks owned by the current user
            $tasks = array_values(array_filter($allTasks, function ($t) use ($userTaskIds) {
                $tid = $t['id'] ?? $t['taskId'] ?? $t['gid'] ?? '';
                return in_array($tid, $userTaskIds, true);
            }));

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
            $task = $this->motrixClient->getTask($taskId);
            if (!$task) {
                return new DataResponse(['success' => false, 'error' => 'Task not found in Motrix'], Http::STATUS_NOT_FOUND);
            }

            // Auto-rescan folder if task has finished
            $status = $task['status'] ?? '';
            if (in_array($status, ['complete', 'completed', 'stopped'], true)) {
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
            $maxActiveTasks = (int)$this->config->getAppValue('motrix', 'max_active_tasks_per_user', '5');
            if ($maxActiveTasks > 0) {
                $userTaskIds = $this->taskOwnershipService->getUserTaskIds($userId);
                if (!empty($userTaskIds)) {
                    $allTasks = $this->motrixClient->listTasks();
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
            $baseSaveDir = rtrim($this->motrixClient->getDefaultSaveDir(), '/');
            $saveDir = $baseSaveDir . '/' . $userId . '/' . ltrim($internalRelPath, '/');

            // 4. Send download to Motrix server
            if ($kind === 'url') {
                $task = $this->motrixClient->addUrl([$url], $saveDir, $filename);
            } elseif ($kind === 'magnet') {
                $task = $this->motrixClient->addMagnet($magnet, $saveDir);
            } else {
                $task = $this->motrixClient->addTorrent($torrent, $saveDir, $filename);
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
            $ok = $this->motrixClient->pauseTask($taskId);
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
            $ok = $this->motrixClient->resumeTask($taskId);
            return new DataResponse(['success' => $ok]);
        } catch (\Throwable $e) {
            return new DataResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function deleteTask(string $taskId, bool $deleteFiles = false): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['success' => false, 'error' => 'User not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        if (!$this->taskOwnershipService->isTaskOwnedBy($taskId, $user->getUID())) {
            return new DataResponse(['success' => false, 'error' => 'Task not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            $ok = $this->motrixClient->removeTask($taskId, $deleteFiles);
            $this->taskOwnershipService->deleteTask($taskId);
            return new DataResponse(['success' => true, 'removed' => $ok]);
        } catch (\Throwable $e) {
            return new DataResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
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
            $task = $this->motrixClient->getTask($taskId);
            if (!$task) {
                // Fallback: search task list in case of ID/GID discrepancy
                $allTasks = $this->motrixClient->listTasks();
                foreach ($allTasks as $t) {
                    if (($t['id'] ?? '') === $taskId || ($t['gid'] ?? '') === $taskId) {
                        $task = $t;
                        break;
                    }
                }
            }

            if (!$task) {
                return new DataResponse(['success' => false, 'error' => 'Task not found in Motrix'], Http::STATUS_NOT_FOUND);
            }

            $effectiveFolder = $targetFolder !== '' ? $targetFolder : ($taskMeta['target_folder'] ?? '');
            $cleanFolder = $this->normalizeTargetFolder($effectiveFolder);

            $result = $this->storageSyncService->syncCompletedTask($userId, $task, $cleanFolder);
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
            // Only expose internal endpoint URL to administrators
            'endpoint' => $isAdmin ? $this->motrixClient->getEndpoint() : '',
            'saveDir' => $this->motrixClient->getDefaultSaveDir(),
            'hasToken' => !empty($this->motrixClient->getToken()),
            'allowPrivateNetwork' => $this->config->getAppValue('motrix', UrlValidator::CONFIG_ALLOW_PRIVATE, 'no') === 'yes',
            'maxActiveTasksPerUser' => (int)$this->config->getAppValue('motrix', 'max_active_tasks_per_user', '5'),
        ]);
    }

    public function saveSettings(
        string $endpoint,
        ?string $token = null,
        ?string $saveDir = null,
        ?string $allowPrivateNetwork = null,
        ?int $maxActiveTasksPerUser = null
    ): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user || !$this->groupManager->isAdmin($user->getUID())) {
            return new DataResponse(['success' => false, 'error' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
        }

        $this->config->setAppValue('motrix', MotrixClient::CONFIG_ENDPOINT, rtrim($endpoint, '/'));

        if ($token !== null && trim($token) !== '') {
            $this->config->setAppValue('motrix', MotrixClient::CONFIG_TOKEN, trim($token));
        }

        if (!empty($saveDir)) {
            $this->config->setAppValue('motrix', MotrixClient::CONFIG_DEFAULT_SAVE_DIR, trim($saveDir));
        }

        if ($allowPrivateNetwork !== null) {
            $val = in_array(strtolower($allowPrivateNetwork), ['yes', 'true', '1'], true) ? 'yes' : 'no';
            $this->config->setAppValue('motrix', UrlValidator::CONFIG_ALLOW_PRIVATE, $val);
        }

        if ($maxActiveTasksPerUser !== null && $maxActiveTasksPerUser >= 0) {
            $this->config->setAppValue('motrix', 'max_active_tasks_per_user', (string)$maxActiveTasksPerUser);
        }

        return new DataResponse(['success' => true]);
    }

    public function testConnection(): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user || !$this->groupManager->isAdmin($user->getUID())) {
            return new DataResponse(['success' => false, 'error' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
        }

        $res = $this->motrixClient->testConnection();
        return new DataResponse($res);
    }
}
