<?php

declare(strict_types=1);

namespace OCA\Motrix\Controller;

use OCA\Motrix\Service\MotrixClient;
use OCA\Motrix\Service\StorageSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Http;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

class ApiController extends Controller {
    private MotrixClient $motrixClient;
    private StorageSyncService $storageSyncService;
    private IUserSession $userSession;
    private IRootFolder $rootFolder;
    private IConfig $config;

    public function __construct(
        string $appName,
        IRequest $request,
        MotrixClient $motrixClient,
        StorageSyncService $storageSyncService,
        IUserSession $userSession,
        IRootFolder $rootFolder,
        IConfig $config
    ) {
        parent::__construct($appName, $request);
        $this->motrixClient = $motrixClient;
        $this->storageSyncService = $storageSyncService;
        $this->userSession = $userSession;
        $this->rootFolder = $rootFolder;
        $this->config = $config;
    }

    /**
     * @NoAdminRequired
     */
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

    /**
     * @NoAdminRequired
     */
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

    /**
     * @NoAdminRequired
     */
    public function getTasks(?string $status = null): DataResponse {
        try {
            $tasks = $this->motrixClient->listTasks($status);
            return new DataResponse(['success' => true, 'tasks' => $tasks]);
        } catch (\Throwable $e) {
            return new DataResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], Http::STATUS_SERVICE_UNAVAILABLE);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function getTask(string $taskId, ?string $targetFolder = null): DataResponse {
        try {
            $task = $this->motrixClient->getTask($taskId);
            if (!$task) {
                return new DataResponse(['success' => false, 'error' => 'Task not found'], Http::STATUS_NOT_FOUND);
            }

            // Auto-rescan folder if task has finished
            $status = $task['status'] ?? '';
            if (in_array($status, ['complete', 'completed', 'stopped'], true)) {
                $user = $this->userSession->getUser();
                if ($user) {
                    $this->storageSyncService->scanPath($user->getUID(), $targetFolder ?? '');
                }
            }

            return new DataResponse(['success' => true, 'task' => $task]);
        } catch (\Throwable $e) {
            return new DataResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function addTask(
        string $kind = 'url',
        ?string $url = null,
        ?string $magnet = null,
        ?string $torrent = null,
        ?string $saveDir = null,
        ?string $filename = null,
        ?string $targetFolder = null
    ): DataResponse {
        try {
            $user = $this->userSession->getUser();
            $userId = $user ? $user->getUID() : 'Admin';

            // Resolve target directory inside Nextcloud user storage
            if (empty($saveDir)) {
                $baseSaveDir = $this->motrixClient->getDefaultSaveDir();
                $cleanFolder = trim($targetFolder ?? '', '/');

                // Ensure destination folder exists in Nextcloud
                if ($user !== null && $cleanFolder !== '') {
                    $userFolder = $this->rootFolder->getUserFolder($userId);
                    if (!$userFolder->nodeExists($cleanFolder)) {
                        $userFolder->newFolder($cleanFolder);
                    }
                }

                // Path in Motrix container (/downloads -> /var/lib/.../data)
                $saveDir = rtrim($baseSaveDir, '/') . '/' . $userId . '/files' . ($cleanFolder !== '' ? '/' . $cleanFolder : '');
            }

            if ($kind === 'url') {
                if (empty($url)) {
                    return new DataResponse(['success' => false, 'error' => 'URL is required'], Http::STATUS_BAD_REQUEST);
                }
                $task = $this->motrixClient->addUrl([$url], $saveDir, $filename);
            } elseif ($kind === 'magnet') {
                if (empty($magnet)) {
                    return new DataResponse(['success' => false, 'error' => 'Magnet URI is required'], Http::STATUS_BAD_REQUEST);
                }
                $task = $this->motrixClient->addMagnet($magnet, $saveDir);
            } elseif ($kind === 'torrent') {
                if (empty($torrent)) {
                    return new DataResponse(['success' => false, 'error' => 'Base64 torrent data is required'], Http::STATUS_BAD_REQUEST);
                }
                $task = $this->motrixClient->addTorrent($torrent, $saveDir, $filename);
            } else {
                return new DataResponse(['success' => false, 'error' => "Unsupported task kind: $kind"], Http::STATUS_BAD_REQUEST);
            }

            return new DataResponse([
                'success' => true,
                'task' => $task,
                'saveDir' => $saveDir,
                'targetFolder' => $targetFolder ?? '',
            ]);
        } catch (\Throwable $e) {
            return new DataResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
     public function pauseTask(string $taskId): DataResponse {
         try {
             $ok = $this->motrixClient->pauseTask($taskId);
             return new DataResponse(['success' => $ok]);
         } catch (\Throwable $e) {
             return new DataResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
         }
     }

     /**
      * @NoAdminRequired
      * @NoCSRFRequired
      */
     public function resumeTask(string $taskId): DataResponse {
         try {
             $ok = $this->motrixClient->resumeTask($taskId);
             return new DataResponse(['success' => $ok]);
         } catch (\Throwable $e) {
             return new DataResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
         }
     }

     /**
      * @NoAdminRequired
      * @NoCSRFRequired
      */
     public function deleteTask(string $taskId, bool $deleteFiles = false): DataResponse {
         try {
             $ok = $this->motrixClient->removeTask($taskId, $deleteFiles);
             return new DataResponse(['success' => true, 'removed' => $ok]);
         } catch (\Throwable $e) {
             return new DataResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
         }
     }

     /**
      * @NoAdminRequired
      * @NoCSRFRequired
      */
     public function syncTask(string $taskId, string $targetFolder = 'Downloads'): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['success' => false, 'error' => 'User not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $task = $this->motrixClient->getTask($taskId);
            if (!$task) {
                return new DataResponse(['success' => false, 'error' => 'Task not found in Motrix'], Http::STATUS_NOT_FOUND);
            }

            $result = $this->storageSyncService->syncCompletedTask($user->getUID(), $task, $targetFolder);
            return new DataResponse(['success' => true, 'result' => $result]);
        } catch (\Throwable $e) {
            return new DataResponse(['success' => false, 'error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function getSettings(): DataResponse {
        return new DataResponse([
            'success' => true,
            'endpoint' => $this->motrixClient->getEndpoint(),
            'saveDir' => $this->motrixClient->getDefaultSaveDir(),
            'hasToken' => !empty($this->motrixClient->getToken()),
        ]);
    }

    public function saveSettings(string $endpoint, ?string $token = null, ?string $saveDir = null): DataResponse {
        $this->config->setAppValue('motrix', MotrixClient::CONFIG_ENDPOINT, rtrim($endpoint, '/'));

        if ($token !== null) {
            $this->config->setAppValue('motrix', MotrixClient::CONFIG_TOKEN, trim($token));
        }

        if (!empty($saveDir)) {
            $this->config->setAppValue('motrix', MotrixClient::CONFIG_DEFAULT_SAVE_DIR, trim($saveDir));
        }

        return new DataResponse(['success' => true]);
    }

    /**
     * @NoAdminRequired
     */
    public function scanPath(?string $targetFolder = null): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user) {
            return new DataResponse(['success' => false, 'error' => 'User not logged in'], Http::STATUS_UNAUTHORIZED);
        }

        $res = $this->storageSyncService->scanPath($user->getUID(), $targetFolder ?? '');
        return new DataResponse($res);
    }

    /**
     * @NoAdminRequired
     */
    public function testConnection(): DataResponse {
        $result = $this->motrixClient->testConnection();
        return new DataResponse($result);
    }
}
