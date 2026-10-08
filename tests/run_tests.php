<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Unit/UrlValidatorTest.php';
require_once __DIR__ . '/Unit/StorageSyncServiceTest.php';

use OCA\NdDownloader\Tests\Unit\UrlValidatorTest;
use OCA\NdDownloader\Tests\Unit\StorageSyncServiceTest;

$testClasses = [
    UrlValidatorTest::class,
    StorageSyncServiceTest::class,
];

$passed = 0;
$failed = 0;

echo "Running ND Downloader Unit Tests...\n\n";

foreach ($testClasses as $testClass) {
    echo "Suite: {$testClass}\n";
    $instance = new $testClass();
    $methods = get_class_methods($instance);

    foreach ($methods as $method) {
        if (!str_starts_with($method, 'test')) {
            continue;
        }

        try {
            $instance->$method();
            echo "  [PASS] {$method}\n";
            $passed++;
        } catch (\Throwable $e) {
            echo "  [FAIL] {$method}: {$e->getMessage()}\n";
            $failed++;
        }
    }
    echo "\n";
}

echo "Summary: {$passed} passed, {$failed} failed.\n";

if ($failed > 0) {
    exit(1);
}

exit(0);
