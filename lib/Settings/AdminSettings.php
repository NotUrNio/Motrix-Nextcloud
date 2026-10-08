<?php

declare(strict_types=1);

namespace OCA\NdDownloader\Settings;

use OCA\NdDownloader\Service\NdDownloaderClient;
use OCA\NdDownloader\Service\UrlValidator;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
    private NdDownloaderClient $ndClient;
    private IConfig $config;

    public function __construct(NdDownloaderClient $ndClient, IConfig $config) {
        $this->ndClient = $ndClient;
        $this->config = $config;
    }

    public function getForm(): TemplateResponse {
        $response = new TemplateResponse('nddownloader', 'admin', [
            'endpoint' => $this->ndClient->getEndpoint(),
            'saveDir' => $this->ndClient->getDefaultSaveDir(),
            'hasToken' => !empty($this->ndClient->getToken()),
            'domainAllowlist' => (string)$this->config->getAppValue('nddownloader', UrlValidator::CONFIG_DOMAIN_ALLOWLIST, ''),
            'domainDenylist' => (string)$this->config->getAppValue('nddownloader', UrlValidator::CONFIG_DOMAIN_DENYLIST, ''),
            'allowPrivateNetwork' => $this->config->getAppValue('nddownloader', UrlValidator::CONFIG_ALLOW_PRIVATE, 'no') === 'yes',
        ], '');
        $response->addScript('nddownloader', 'admin-settings');

        return $response;
    }

    public function getSection(): string {
        return 'additional';
    }

    public function getPriority(): int {
        return 50;
    }
}
