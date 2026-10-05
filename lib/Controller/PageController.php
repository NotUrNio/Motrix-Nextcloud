<?php

declare(strict_types=1);

namespace OCA\Motrix\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
    public function __construct(string $appName, IRequest $request) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): TemplateResponse {
        Util::addScript('motrix', 'app');
        Util::addStyle('motrix', 'style');

        return new TemplateResponse('motrix', 'main', [], 'user');
    }
}
