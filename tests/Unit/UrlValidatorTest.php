<?php

declare(strict_types=1);

namespace OCA\NdDownloader\Tests\Unit;

use InvalidArgumentException;
use OCA\NdDownloader\Service\UrlValidator;
use OCP\IConfig;

class UrlValidatorTest {
    private function createConfig(array $appValues = []): IConfig {
        return new class($appValues) implements IConfig {
            private array $values;
            public function __construct(array $values) { $this->values = $values; }
            public function getAppValue(string $app, string $key, $default = null) {
                return $this->values[$key] ?? $default;
            }
            public function setAppValue(string $app, string $key, $value) {
                $this->values[$key] = $value;
            }
            public function deleteAppValue(string $app, string $key) {
                unset($this->values[$key]);
            }
            public function getSystemValue(string $key, $default = null) {
                return $default;
            }
        };
    }

    public function testAcceptsValidPublicHttpUrl(): void {
        $validator = new UrlValidator($this->createConfig());
        $validator->validate('https://www.gnu.org/licenses/agpl-3.0.html');
        // No exception thrown
    }

    public function testAcceptsValidMagnetLink(): void {
        $validator = new UrlValidator($this->createConfig());
        $validator->validate('magnet:?xt=urn:btih:d6b0c42e3f0f9608d08c5e6d6288bb7d0a2f77b7&dn=sample');
        // No exception thrown
    }

    public function testRejectsEmptyUrl(): void {
        $validator = new UrlValidator($this->createConfig());
        try {
            $validator->validate('');
            throw new \Exception('Expected InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $e) {
            assert(str_contains($e->getMessage(), 'URL cannot be empty'));
        }
    }

    public function testRejectsUnsupportedScheme(): void {
        $validator = new UrlValidator($this->createConfig());
        try {
            $validator->validate('file:///etc/passwd');
            throw new \Exception('Expected InvalidArgumentException was not thrown');
        } catch (InvalidArgumentException $e) {
            assert(str_contains($e->getMessage(), 'Unsupported URL scheme'));
        }
    }

    public function testBlocksLocalhostAndLoopback(): void {
        $validator = new UrlValidator($this->createConfig());

        $blocked = [
            'http://localhost/admin',
            'http://127.0.0.1:8080/secret',
            'http://app.localhost/test',
        ];

        foreach ($blocked as $url) {
            try {
                $validator->validate($url);
                throw new \Exception("Expected {$url} to be blocked");
            } catch (InvalidArgumentException $e) {
                // Expected
            }
        }
    }

    public function testBlocksCloudMetadataEndpoints(): void {
        $validator = new UrlValidator($this->createConfig(['allow_private_network' => 'yes']));

        $blocked = [
            'http://169.254.169.254/latest/meta-data/',
            'http://metadata.google.internal/computeMetadata/v1/',
            'http://instance-data/latest/meta-data/',
        ];

        foreach ($blocked as $url) {
            try {
                $validator->validate($url);
                throw new \Exception("Expected {$url} to be blocked even with private networks enabled");
            } catch (InvalidArgumentException $e) {
                // Expected
            }
        }
    }

    public function testBlocksPrivateSubnetsWhenDisabled(): void {
        $validator = new UrlValidator($this->createConfig(['allow_private_network' => 'no']));

        $privateUrls = [
            'http://10.0.0.5/file.iso',
            'http://192.168.1.100/backup.tar.gz',
            'http://172.16.0.10/database.sql',
        ];

        foreach ($privateUrls as $url) {
            try {
                $validator->validate($url);
                throw new \Exception("Expected {$url} to be blocked when private networks disabled");
            } catch (InvalidArgumentException $e) {
                assert(str_contains($e->getMessage(), 'Private network address blocked'));
            }
        }
    }

    public function testEnforcesDomainAllowlist(): void {
        $config = $this->createConfig([
            'domain_allowlist' => '*.archive.org, debian.org',
        ]);
        $validator = new UrlValidator($config);

        // Allowed
        $validator->validate('https://archive.org/details/test');
        $validator->validate('https://ia800100.us.archive.org/test.iso');
        $validator->validate('https://debian.org/dist/iso');

        // Blocked
        try {
            $validator->validate('https://example.com/test.zip');
            throw new \Exception('Expected example.com to be blocked by allowlist');
        } catch (InvalidArgumentException $e) {
            assert(str_contains($e->getMessage(), 'allowlist'));
        }
    }

    public function testEnforcesDomainDenylist(): void {
        $config = $this->createConfig([
            'domain_denylist' => 'malicious.com, *.internal.net',
        ]);
        $validator = new UrlValidator($config);

        // Allowed
        $validator->validate('https://gnu.org/licenses');

        // Blocked
        try {
            $validator->validate('https://malicious.com/virus.exe');
            throw new \Exception('Expected malicious.com to be blocked by denylist');
        } catch (InvalidArgumentException $e) {
            assert(str_contains($e->getMessage(), 'denylist'));
        }
    }
}
