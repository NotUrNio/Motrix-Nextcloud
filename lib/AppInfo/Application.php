<?php

declare(strict_types=1);

namespace OCA\Motrix\AppInfo;

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
        // Services are auto-wired by Nextcloud's container
    }

    public function boot(IBootContext $context): void {
        // App lifecycle hooks
    }
}
