<?php

declare(strict_types=1);

namespace OCA\NdDownloader\Migration;

use Closure;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Security\ICrypto;

class Version1001Date20261008000000 extends SimpleMigrationStep {
    private IConfig $config;
    private ICrypto $crypto;

    public function __construct(?IConfig $config = null, ?ICrypto $crypto = null) {
        $this->config = $config ?? \OC::$server->get(IConfig::class);
        $this->crypto = $crypto ?? \OC::$server->get(ICrypto::class);
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        try {
            $token = (string)$this->config->getAppValue('nddownloader', 'nddownloader_token', '');
            if ($token !== '') {
                try {
                    // Test if already encrypted
                    $this->crypto->decrypt($token);
                } catch (\Throwable $e) {
                    // Plaintext token: encrypt with Nextcloud instance secret
                    $encrypted = $this->crypto->encrypt($token);
                    $this->config->setAppValue('nddownloader', 'nddownloader_token', $encrypted);
                }
            }
        } catch (\Throwable $e) {
            // Non-fatal if config or crypto is unavailable during migration runner
        }
    }
}
