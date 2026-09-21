<?php
declare(strict_types=1);

require_once __DIR__ . '/../scripts/migrate-clone-url.php';

$cases = [
    'https://clone.example.org:80' => 'https://clone.example.org',
    'https://clone.example.org:80/shop/' => 'https://clone.example.org/shop',
    'https://clone.example.org' => 'https://clone.example.org',
    'https://clone.example.org:8443' => 'https://clone.example.org:8443',
    'http://clone.example.org:80' => 'http://clone.example.org:80',
];

foreach ($cases as $input => $expected) {
    $actual = normalizedCloneUrl($input);
    if ($actual !== $expected) {
        fwrite(STDERR, "URL normalization failed: $input => $actual (expected $expected)\n");
        exit(1);
    }
}

echo "Clone URL normalization: OK\n";
