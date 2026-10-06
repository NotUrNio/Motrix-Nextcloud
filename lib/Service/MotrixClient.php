<?php

declare(strict_types=1);

namespace OCA\Motrix\Service;

use OCP\IConfig;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use RuntimeException;

class MotrixClient {
    public const CONFIG_ENDPOINT = 'motrix_endpoint';
    public const CONFIG_TOKEN = 'motrix_token';
    public const CONFIG_DEFAULT_SAVE_DIR = 'motrix_save_dir';

    private IConfig $config;
    private IClientService $clientService;
    private LoggerInterface $logger;

    public function __construct(
        IConfig $config,
        IClientService $clientService,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->clientService = $clientService;
        $this->logger = $logger;
    }

    public function getEndpoint(): string {
        $endpoint = (string)$this->config->getAppValue('motrix', self::CONFIG_ENDPOINT, 'http://127.0.0.1:16801');
        return rtrim($endpoint, '/');
    }

    public function getToken(): string {
        $token = (string)$this->config->getAppValue('motrix', self::CONFIG_TOKEN, '');
        if (!empty($token)) {
            return $token;
        }

        $candidates = [
            '/downloads/bridge/endpoint.json',
            '/home/container/motrix/bridge/endpoint.json',
            '/data/bridge/endpoint.json',
        ];
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $content = @file_get_contents($candidate);
                if ($content !== false) {
                    $json = json_decode($content, true);
                    if (!empty($json['localToken'])) {
                        return (string)$json['localToken'];
                    }
                }
            }
        }

        return '';
    }

    public function getDefaultSaveDir(): string {
        return (string)$this->config->getAppValue('motrix', self::CONFIG_DEFAULT_SAVE_DIR, '/downloads');
    }

    /**
     * Executes a JSON-RPC 2.0 call to the Motrix MDXP endpoint.
     */
    public function call(string $method, array $params = []): mixed {
        $endpoint = $this->getEndpoint() . '/mdxp';
        $token = $this->getToken();

        $payload = [
            'jsonrpc' => '2.0',
            'id' => uniqid('nc_motrix_', true),
            'method' => $method,
            'params' => empty($params) ? new \stdClass() : $params,
        ];

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if (!empty($token)) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        try {
            $client = $this->clientService->newClient();
            $response = $client->post($endpoint, [
                'headers' => $headers,
                'body' => json_encode($payload, JSON_THROW_ON_ERROR),
                'timeout' => 15,
                'connect_timeout' => 5,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new RuntimeException("Motrix server responded with HTTP status code $statusCode");
            }

            $body = $response->getBody();
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $lower = strtolower($msg);
            if (
                str_contains($lower, 'host') ||
                str_contains($lower, 'private') ||
                str_contains($lower, 'local') ||
                str_contains($lower, 'loopback') ||
                str_contains($lower, 'not allowed') ||
                str_contains($lower, 'connect')
            ) {
                throw new RuntimeException(
                    "Unable to connect to Motrix server ({$msg}). If Motrix is hosted on a local or private address, please enable 'allow_local_remote_servers' => true in Nextcloud's config/config.php.",
                    0,
                    $e
                );
            }
            throw new RuntimeException('Unable to communicate with Motrix: ' . $msg, 0, $e);
        }

        if (isset($data['error'])) {
            $errCode = $data['error']['code'] ?? -1;
            $errMsg = $data['error']['message'] ?? 'Unknown JSON-RPC error';
            throw new RuntimeException("Motrix JSON-RPC error [$errCode]: $errMsg");
        }

        return $data['result'] ?? null;
    }

    public function getEngineStatus(): array {
        $result = $this->call('engine/status');
        return is_array($result) ? $result : ['state' => 'unknown'];
    }

    public function getStats(): array {
        $result = $this->call('stats/get');
        return is_array($result) ? $result : [
            'totalDownloadSpeed' => 0,
            'totalUploadSpeed' => 0,
            'activeTasks' => 0,
            'waitingTasks' => 0,
            'stoppedTasks' => 0,
        ];
    }

    public function listTasks(?string $status = null, int $limit = 50, int $offset = 0): array {
        $params = [
            'limit' => $limit,
            'offset' => $offset,
        ];
        if (!empty($status)) {
            $params['status'] = $status;
        }

        $result = $this->call('task/list', $params);
        return $result['tasks'] ?? [];
    }

    public function getTask(string $taskId): ?array {
        $result = $this->call('task/get', ['taskId' => $taskId]);
        return $result['task'] ?? null;
    }

    public function addUrl(array $uris, ?string $saveDir = null, ?string $filename = null): array {
        $dir = !empty($saveDir) ? $saveDir : $this->getDefaultSaveDir();
        $params = [
            'kind' => 'url',
            'saveDir' => $dir,
            'uris' => array_values($uris),
        ];

        if (!empty($filename)) {
            $params['filename'] = $filename;
        }

        return (array)$this->call('download/add', $params);
    }

    public function addMagnet(string $uri, ?string $saveDir = null): array {
        $dir = !empty($saveDir) ? $saveDir : $this->getDefaultSaveDir();
        $params = [
            'kind' => 'magnet',
            'saveDir' => $dir,
            'uri' => $uri,
        ];

        return (array)$this->call('download/add', $params);
    }

    public function addTorrent(string $base64Torrent, ?string $saveDir = null, ?string $displayName = null): array {
        $dir = !empty($saveDir) ? $saveDir : $this->getDefaultSaveDir();
        $params = [
            'kind' => 'torrent',
            'saveDir' => $dir,
            'base64' => $base64Torrent,
        ];

        if (!empty($displayName)) {
            $params['displayName'] = $displayName;
        }

        return (array)$this->call('download/add', $params);
    }

    public function pauseTask(string $taskId): bool {
        $result = $this->call('task/pause', ['taskId' => $taskId]);
        return !empty($result['ok']);
    }

    public function resumeTask(string $taskId): bool {
        $result = $this->call('task/resume', ['taskId' => $taskId]);
        return !empty($result['ok']);
    }

    public function removeTask(string $taskId, bool $deleteFiles = false): bool {
        try {
            $result = $this->call('task/remove', [
                'taskId' => $taskId,
                'deleteFiles' => $deleteFiles,
            ]);
            return !empty($result['ok']);
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'not found') || str_contains($msg, '404')) {
                return true;
            }
            throw $e;
        }
    }

    public function testConnection(): array {
        try {
            $engineStatus = $this->getEngineStatus();
            $stats = $this->getStats();
            return [
                'success' => true,
                'engine' => $engineStatus,
                'stats' => $stats,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
