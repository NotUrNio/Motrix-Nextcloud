<?php

declare(strict_types=1);

namespace OCA\Motrix\Settings;

use OCA\Motrix\Service\MotrixClient;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
    private IConfig $config;

    public function __construct(IConfig $config) {
        $this->config = $config;
    }

    public function getForm(): TemplateResponse {
        Util::addScript('motrix', 'admin-settings');

        $endpoint = (string)$this->config->getAppValue('motrix', MotrixClient::CONFIG_ENDPOINT, 'http://127.0.0.1:16801');
        $saveDir = (string)$this->config->getAppValue('motrix', MotrixClient::CONFIG_DEFAULT_SAVE_DIR, '/downloads');
        $hasToken = !empty($this->config->getAppValue('motrix', MotrixClient::CONFIG_TOKEN, ''));

        return new TemplateResponse('motrix', 'admin', [
            'endpoint' => $endpoint,
            'saveDir' => $saveDir,
            'hasToken' => $hasToken,
        ], '');
    }

    public function getSection(): string {
        return 'additional';
    }

    public function getPriority(): int {
        return 50;
    }
}
