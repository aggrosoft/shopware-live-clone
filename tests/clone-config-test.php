<?php
declare(strict_types=1);
require __DIR__ . '/../scripts/configure-clone.php';
$check = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$rows = [
    ['id' => '1', 'url' => 'https://live.example.org'],
    ['id' => '2', 'url' => 'https://live.example.org/en'],
    ['id' => '3', 'url' => 'http://live.example.org'],
    ['id' => '4', 'url' => 'https://second.example.org/en'],
];
$mapped = cloneDomainMap($rows, 'https://live.example.org', 'https://test.example.org');
$check($mapped[0]['target'] === 'https://test.example.org', 'Main domain mapped.');
$check($mapped[1]['target'] === 'https://test.example.org/en', 'Language path preserved.');
$check(count(array_unique(array_column($mapped, 'target'))) === 4, 'Aliases and secondary channels remain distinct.');
$check(strpos($mapped[3]['target'], 'https://test.example.org/__clone/') === 0, 'Secondary channel stays on target host.');
$subpath = cloneDomainMap([['id' => '1', 'url' => 'https://live.example.org/shop'], ['id' => '2', 'url' => 'https://live.example.org/shop/en']], 'https://live.example.org/shop', 'https://test.example.org');
$check($subpath[1]['target'] === 'https://test.example.org/en', 'Source subdirectory stripped.');
$legacy = cloneDomainMap([['id' => '1', 'url' => 'localhost'], ['id' => '2', 'url' => ''], ['id' => '3', 'url' => '%env(APP_URL)%']], 'http://localhost', 'https://test.example.org');
$check($legacy[0]['target'] === 'https://test.example.org', 'Scheme-less legacy main domain mapped.');
$check(count(array_unique(array_column($legacy, 'target'))) === 3, 'Placeholder domains receive distinct local URLs.');
foreach (['https://user@host', 'https://user:pass@host', 'file:///tmp/test', 'https://host?x=1'] as $url) {
    $rejected = false;
    try { cloneUrl($url); } catch (RuntimeException $expected) { $rejected = true; }
    $check($rejected, 'Invalid URL accepted.');
}
echo "Clone URL configuration tests: OK\n";
