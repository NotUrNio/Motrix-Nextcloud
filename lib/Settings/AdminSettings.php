<?php

declare(strict_types=1);

namespace OCA\NdDownloader\Settings;

use OCA\NdDownloader\Service\NdDownloaderClient;
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
        Util::addScript('nddownloader', 'admin-settings');

        $endpoint = (string)$this->config->getAppValue('nddownloader', NdDownloaderClient::CONFIG_ENDPOINT, '');
        if (empty($endpoint) || $endpoint === 'http://motrix-server:16801') {
            $endpoint = (string)$this->config->getAppValue('nddownloader', 'motrix_endpoint', (string)$this->config->getAppValue('motrix', 'motrix_endpoint', 'http://nd-server:16801'));
        }
        if (empty($endpoint) || $endpoint === 'http://motrix-server:16801') {
            $endpoint = 'http://nd-server:16801';
        }

        $saveDir = (string)$this->config->getAppValue('nddownloader', NdDownloaderClient::CONFIG_DEFAULT_SAVE_DIR, '');
        if (empty($saveDir)) {
            $saveDir = (string)$this->config->getAppValue('nddownloader', 'motrix_save_dir', (string)$this->config->getAppValue('motrix', 'motrix_save_dir', '/downloads'));
        }

        $hasToken = !empty($this->config->getAppValue('nddownloader', NdDownloaderClient::CONFIG_TOKEN, ''))
            || !empty($this->config->getAppValue('motrix', 'motrix_token', ''));

        return new TemplateResponse('nddownloader', 'admin', [
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
