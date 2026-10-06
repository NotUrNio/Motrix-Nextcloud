<?php

declare(strict_types=1);

namespace OCA\Motrix\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Motrix\Listener\FilesLoadAdditionalScriptsListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'motrix';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(
            LoadAdditionalScriptsEvent::class,
            FilesLoadAdditionalScriptsListener::class
        );
    }

    public function boot(IBootContext $context): void {
        // App lifecycle hooks
    }
}
