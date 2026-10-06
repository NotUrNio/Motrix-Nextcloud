<?php

declare(strict_types=1);

namespace OCA\Motrix\Controller;

use OCA\Motrix\Service\MotrixClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class SettingsController extends Controller {
    private IConfig $config;
    private IUserSession $userSession;
    private IGroupManager $groupManager;

    public function __construct(
        string $appName,
        IRequest $request,
        IConfig $config,
        IUserSession $userSession,
        IGroupManager $groupManager
    ) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->userSession = $userSession;
        $this->groupManager = $groupManager;
    }

    /**
     * Saves admin settings. Token is write-only: blank value keeps existing token.
     */
    public function save(
        string $endpoint,
        ?string $saveDir = null,
        ?string $token = null
    ): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user || !$this->groupManager->isAdmin($user->getUID())) {
            return new DataResponse(['success' => false, 'error' => 'Admin privileges required'], Http::STATUS_FORBIDDEN);
        }

        $endpoint = trim($endpoint);
        if ($endpoint !== '') {
            $this->config->setAppValue('motrix', MotrixClient::CONFIG_ENDPOINT, rtrim($endpoint, '/'));
        }

        if ($token !== null && trim($token) !== '') {
            $this->config->setAppValue('motrix', MotrixClient::CONFIG_TOKEN, trim($token));
        }

        if ($saveDir !== null && trim($saveDir) !== '') {
            $this->config->setAppValue('motrix', MotrixClient::CONFIG_DEFAULT_SAVE_DIR, trim($saveDir));
        }

        return new DataResponse(['success' => true]);
    }
}
