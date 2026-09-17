<?php
// Executed only in the disposable official Dockware CI source container.
declare(strict_types=1);
require '/tmp/source-database.php';
$root = '/var/www/html';
$env = databaseEnvironment($root, []);
$env['APP_ENV'] = 'prod';
$env['SHOPWARE_ES_ENABLED'] = '1';
$env['SHOPWARE_ES_INDEXING_ENABLED'] = '1';
$env['OPENSEARCH_URL'] = 'http://live-search.invalid:9200';
$env['REDIS_URL'] = 'redis://live-redis.invalid:6379/4';
file_put_contents($root . '/.env.local.php', '<?php return ' . var_export($env, true) . ';');
if (!is_dir($root . '/config/packages/prod')) { mkdir($root . '/config/packages/prod', 0770, true); }
file_put_contents($root . '/config/packages/prod/ci-redis.yaml', "framework:\n  cache:\n    app: cache.adapter.redis\n    default_redis_provider: '%env(REDIS_URL)%'\n");
$pdo = new PDO('mysql:host=127.0.0.1;dbname=shopware', 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES ('LIVE_QUEUE_SENTINEL','{}','default', NOW(), NOW())");
echo "Prepared CI source with search, Redis and a live queue sentinel.\n";
