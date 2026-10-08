<?php

declare(strict_types=1);

namespace OCA\NdDownloader\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class PageController extends Controller {
    public function __construct(string $appName, IRequest $request) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        $response = new TemplateResponse('nddownloader', 'main', [], 'user');
        $response->addScript('nddownloader', 'app');
        $response->addStyle('nddownloader', 'style');

        return $response;
    }
}
