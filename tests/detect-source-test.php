<?php
declare(strict_types=1);
require __DIR__ . '/../scripts/detect-source.php';

$fixture = sys_get_temp_dir() . '/clone-detect-' . bin2hex(random_bytes(8));
mkdir($fixture . '/shop/public', 0700, true);
mkdir($fixture . '/shop/bin');
mkdir($fixture . '/shop/vendor');
mkdir($fixture . '/shop/config/packages', 0700, true);
$shop = $fixture . '/shop';
$check = static function (bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
};
try {
    file_put_contents($shop . '/composer.lock', json_encode(['packages' => [['name' => 'shopware/core', 'version' => 'v6.7.1.0', 'require' => ['php' => '~8.2.0 || ~8.3.0']]]]));
    file_put_contents($shop . '/bin/console', '<?php throw new Exception("MUST NOT RUN");');
    file_put_contents($shop . '/vendor/autoload.php', '<?php throw new Exception("MUST NOT RUN");');
    file_put_contents($fixture . '/.htaccess', 'AddHandler application/x-httpd-php82 .php');
    file_put_contents($shop . '/public/.htaccess', "# AddHandler application/x-httpd-php74 .php\nAddHandler application/x-httpd-php83 .php\n");
    file_put_contents($shop . '/.env', "APP_ENV=prod\nDATABASE_URL=\"mysql://user:TOP_SECRET@db/shop\"\nSHOPWARE_ES_HOSTS='http://elastic:9200'\nREDIS_URL=redis://redis:6379\nMAILER_DSN=smtp://user:TOP_SECRET@smtp\n");
    file_put_contents($shop . '/.env.local', "SHOPWARE_ES_HOSTS=\n");
    file_put_contents($shop . '/.env.prod.local', "SHOPWARE_ES_HOSTS='http://search:9200'\n");
    $report = inspectShop($shop);
    $check($report['php']['selected'] === '8.3', 'Nearest PHP handler must win.');
    $check($report['shopware']['version'] === 'v6.7.1.0', 'Version read from lock.');
    $check($report['services']['database']['driver'] === 'mysql', 'Database scheme detection.');
    $check($report['services']['search']['configured'] === true, 'Environment-specific override applied.');
    $check($report['services']['redis']['configured'] === true, 'Redis found.');
    $check(strpos(json_encode($report), 'TOP_SECRET') === false, 'Credentials must not appear in report.');
    $check(strpos(json_encode($report), 'mysql://') === false, 'DSNs must not appear in report.');
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=0', '--', base64_encode($shop)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], file_get_contents(__DIR__ . '/../scripts/detect-source.php'));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $check(proc_close($process) === 0 && $stderr === '', 'PHP stdin transport failed.');
    $check(json_decode($stdout, true)['schema_version'] === 1, 'SSH stdin transport returns report.');
    file_put_contents($shop . '/.env.local.php', '<?php throw new Exception("MUST NOT RUN");');
    file_put_contents($shop . '/config/packages/services.yaml', "framework:\n  cache:\n    default_redis_provider: 'redis://user:TOP_SECRET@cache'\n");
    file_put_contents($shop . '/.env.prod.local', 'SHOPWARE_ES_HOSTS=${SEARCH_URL}');
    $report = inspectShop($shop);
    $check($report['configuration']['compiled_env_present'] === true, 'Compiled config flagged without execution.');
    $check(count($report['configuration']['service_config_files']) === 1, 'Custom config detected.');
    $check($report['services']['search']['effective_verified'] === false, 'Unresolved config must not be claimed verified.');
    unlink($shop . '/public/.htaccess');
    $check(inspectShop($shop)['php']['selected'] === '8.2', 'Parent handler inherited.');
    unlink($fixture . '/.htaccess');
    $check(inspectShop($shop)['php']['source'] === 'cli', 'CLI fallback identified.');
    file_put_contents($shop . '/public/.htaccess', 'AddHandler application/x-httpd-php74 .php');
    $check(inspectShop($shop)['php']['supported_by_image'] === false, 'Unsupported PHP identified.');
    try { inspectShop($fixture); throw new LogicException('Invalid path accepted.'); }
    catch (RuntimeException $expected) {}
    echo "Source inspection fixture tests: OK\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($fixture);
}
