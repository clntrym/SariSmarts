<?php
/* Runs each test file in its own PHP process so one fatal error cannot hide
   the rest of the suite. */
$files = glob(__DIR__ . '/test_*.php');
sort($files);

$failed = [];

foreach ($files as $file) {
    echo "\n=== " . basename($file) . " ===\n";
    passthru('"' . PHP_BINARY . '" ' . escapeshellarg($file), $code);

    if ($code !== 0) {
        $failed[] = basename($file);
    }
}

echo "\n";

if ($failed !== []) {
    echo 'FAILED: ' . implode(', ', $failed) . "\n";
    exit(1);
}

echo "ALL TESTS PASSED\n";
