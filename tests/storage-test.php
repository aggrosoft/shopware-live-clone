<?php
declare(strict_types=1);
require '/opt/shopware-live-clone/storage-clone.php';
$data = $argv[1];
$root = $data . '/shop';
mkdir($root);
$config = ['type' => 'amazon-s3', 'config' => ['bucket' => 'fixture', 'region' => 'us-east-1', 'endpoint' => 'http://127.0.0.1:18887', 'use_path_style_endpoint' => true, 'credentials' => ['key' => '%env(S3_KEY)%', 'secret' => '%env(S3_SECRET)%']]];
$public = $private = $config;
$public['config']['root'] = 'public';
$private['config']['root'] = 'private';
$overrides = cloneImportStorage(['shopware' => ['filesystem' => ['public' => $public, 'private' => $private], 'cdn' => ['url' => '%env(CDN_URL)%']]], ['S3_KEY' => 'fixture-key', 'S3_SECRET' => 'fixture-secret', 'CDN_URL' => 'https://live-cdn.invalid'], $root, $data);
$check = static function (bool $condition): void { if (!$condition) { throw new RuntimeException('Storage assertion failed.'); } };
$check(file_get_contents($root . '/public/media/product.svg') === '<svg>fixture image</svg>');
$check(file_get_contents($root . '/public/thumbnail/product.svg') === '<svg>fixture thumbnail</svg>');
$check(file_get_contents($root . '/files/document.pdf') === 'fixture document');
$check(!file_exists($root . '/public/sitemap/live.xml'));
$check($overrides === ['S3_KEY' => '', 'S3_SECRET' => '', 'CDN_URL' => '']);
$check(glob($data . '/s3-read-*') === []);
foreach (cloneLocalFilesystems('https://clone.example.test') as $name => $storage) {
    $check($storage['type'] === 'local');
    $check(!isset($storage['config']['credentials']));
    $check(!isset($storage['url']) || $storage['url'] === 'https://clone.example.test');
}
$check(count(cloneLocalFilesystems('https://clone.example.test')) === 6);
echo "S3 media/private files copied locally; generated sitemap excluded; credentials cleared; all six adapters local: OK\n";
