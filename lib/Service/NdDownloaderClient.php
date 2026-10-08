<?php

declare(strict_types=1);

namespace OCA\NdDownloader\Service;

use OCP\IConfig;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use RuntimeException;

class NdDownloaderClient {
    public const CONFIG_ENDPOINT = 'nddownloader_endpoint';
    public const CONFIG_TOKEN = 'nddownloader_token';
    public const CONFIG_DEFAULT_SAVE_DIR = 'nddownloader_save_dir';
    public const DEFAULT_TOKEN = '6wiYws5ONfV1fg3DAwP1tXiFlOmIc1QWW8RuLKY0tbE';

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
        $endpoint = (string)$this->config->getAppValue('nddownloader', self::CONFIG_ENDPOINT, '');
        if (empty($endpoint)) {
            $endpoint = (string)$this->config->getAppValue('nddownloader', 'nd_endpoint', '');
        }
        if (empty($endpoint)) {
            $endpoint = (string)$this->config->getAppValue('nddownloader', 'endpoint', '');
        }
        if (empty($endpoint)) {
            $endpoint = (string)$this->config->getAppValue('nddownloader', 'motrix_endpoint', (string)$this->config->getAppValue('motrix', 'motrix_endpoint', 'http://nd-server:16801'));
        }
        if (empty($endpoint) || $endpoint === 'http://motrix-server:16801') {
            $endpoint = 'http://nd-server:16801';
        }
        return !empty($endpoint) ? rtrim($endpoint, '/') : 'http://nd-server:16801';
    }

    public function getToken(): string {
        $token = (string)$this->config->getAppValue('nddownloader', self::CONFIG_TOKEN, '');
        if (empty($token)) {
            $token = (string)$this->config->getAppValue('nddownloader', 'nd_token', '');
        }
        if (empty($token)) {
            $token = (string)$this->config->getAppValue('nddownloader', 'token', '');
        }
        if (empty($token)) {
            $token = (string)$this->config->getAppValue('nddownloader', 'motrix_token', (string)$this->config->getAppValue('motrix', 'motrix_token', ''));
        }
        if (!empty($token)) {
            return $token;
        }

        $candidates = [
            '/downloads/bridge/endpoint.json',
            '/downloads/bridge/pairing.json',
            '/home/container/nextcloud/data/bridge/endpoint.json',
            '/home/container/nextcloud/data/bridge/pairing.json',
            '/home/container/nddownloader/bridge/endpoint.json',
            '/home/container/nddownloader/bridge/pairing.json',
            '/home/container/nd/bridge/endpoint.json',
            '/home/container/nd/bridge/pairing.json',
            '/home/container/motrix/bridge/endpoint.json',
            '/data/bridge/endpoint.json',
            '/data/bridge/pairing.json',
        ];
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $content = @file_get_contents($candidate);
                if ($content !== false) {
                    $json = json_decode($content, true);
                    if (is_array($json)) {
                        if (!empty($json['localToken'])) {
                            return (string)$json['localToken'];
                        }
                        if (isset($json[0]['token']) && !empty($json[0]['token'])) {
                            return (string)$json[0]['token'];
                        }
                    }
                }
            }
        }

        return self::DEFAULT_TOKEN;
    }

    public function getDefaultSaveDir(): string {
        $saveDir = (string)$this->config->getAppValue('nddownloader', self::CONFIG_DEFAULT_SAVE_DIR, '');
        if (empty($saveDir)) {
            $saveDir = (string)$this->config->getAppValue('nddownloader', 'nd_save_dir', '');
        }
        if (empty($saveDir)) {
            $saveDir = (string)$this->config->getAppValue('nddownloader', 'save_dir', '');
        }
        if (empty($saveDir)) {
            $saveDir = (string)$this->config->getAppValue('nddownloader', 'motrix_save_dir', (string)$this->config->getAppValue('motrix', 'motrix_save_dir', '/downloads'));
        }
        return !empty($saveDir) ? $saveDir : '/downloads';
    }

    /**
     * Executes a JSON-RPC 2.0 call to the backend server endpoint.
     */
    public function call(string $method, array $params = []): mixed {
        $base = $this->getEndpoint();
        $endpoint = (str_ends_with($base, '/mdxp') || str_ends_with($base, '/jsonrpc'))
            ? $base
            : $base . '/mdxp';
        $token = $this->getToken();

        $payload = [
            'jsonrpc' => '2.0',
            'id' => uniqid('nc_nd_', true),
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

        $data = null;
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
                throw new RuntimeException("ND Downloader server responded with HTTP status code $statusCode");
            }

            $body = $response->getBody();
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $lower = strtolower($msg);

            // Auto-heal on 401 Unauthorized: retry with permanent fallback pairing token
            if (
                (str_contains($lower, '401') || str_contains($lower, 'unauthorized'))
                && $token !== self::DEFAULT_TOKEN
            ) {
                $this->logger->warning('ND Downloader 401 Unauthorized encountered. Retrying with persistent token fallback...');
                try {
                    $retryHeaders = $headers;
                    $retryHeaders['Authorization'] = 'Bearer ' . self::DEFAULT_TOKEN;
                    $retryClient = $this->clientService->newClient();
                    $retryResponse = $retryClient->post($endpoint, [
                        'headers' => $retryHeaders,
                        'body' => json_encode($payload, JSON_THROW_ON_ERROR),
                        'timeout' => 15,
                        'connect_timeout' => 5,
                    ]);
                    if ($retryResponse->getStatusCode() >= 200 && $retryResponse->getStatusCode() < 300) {
                        $retryBody = $retryResponse->getBody();
                        $data = json_decode($retryBody, true, 512, JSON_THROW_ON_ERROR);
                        // Self-heal: persist the working token into app config so future requests don't fail
                        $this->config->setAppValue('nddownloader', self::CONFIG_TOKEN, self::DEFAULT_TOKEN);
                    }
                } catch (\Throwable $retryErr) {
                    // Retry failed, fall through to regular error formatting
                }
            }

            if ($data === null) {
                if (
                    str_contains($lower, 'host') ||
                    str_contains($lower, 'private') ||
                    str_contains($lower, 'local') ||
                    str_contains($lower, 'loopback') ||
                    str_contains($lower, 'not allowed') ||
                    str_contains($lower, 'connect')
                ) {
                    throw new RuntimeException(
                        "Unable to connect to ND Downloader server ({$msg}). If ND Downloader is hosted on a local or private address, please enable 'allow_local_remote_servers' => true in Nextcloud's config/config.php.",
                        0,
                        $e
                    );
                }
                throw new RuntimeException('Unable to communicate with ND Downloader: ' . $msg, 0, $e);
            }
        }

        if (isset($data['error'])) {
            $errCode = $data['error']['code'] ?? -1;
            $errMsg = $data['error']['message'] ?? 'Unknown JSON-RPC error';
            throw new RuntimeException("ND Downloader JSON-RPC error [$errCode]: $errMsg");
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
            if (str_contains($msg, 'not found')) {
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
