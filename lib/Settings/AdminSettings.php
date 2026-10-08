<?php

declare(strict_types=1);

namespace OCA\NdDownloader\Settings;

use OCA\NdDownloader\Service\NdDownloaderClient;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
    private NdDownloaderClient $ndClient;

    public function __construct(NdDownloaderClient $ndClient) {
        $this->ndClient = $ndClient;
    }

    public function getForm(): TemplateResponse {
        $response = new TemplateResponse('nddownloader', 'admin', [
            'endpoint' => $this->ndClient->getEndpoint(),
            'saveDir' => $this->ndClient->getDefaultSaveDir(),
            'hasToken' => !empty($this->ndClient->getToken()),
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
