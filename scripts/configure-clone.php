<?php
declare(strict_types=1);
require_once __DIR__ . '/source-database.php';
require_once __DIR__ . '/storage-clone.php';
require_once __DIR__ . '/clone-database.php';

function cloneUrl(string $url): string
{
    $parts = parse_url($url);
    if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('Use an absolute HTTP(S) test URL without credentials or query parameters.');
    }
    return rtrim($url, '/');
}

function clonePublicHtaccess(): string
{
    $contents = file_get_contents(__DIR__ . '/clone-public.htaccess');
    if ($contents === false) { throw new RuntimeException('Clone Apache configuration is missing.'); }
    return rtrim($contents, "\r\n");
}

function cloneDomainMap(array $rows, string $source, string $target): array
{
    $source = cloneUrl($source);
    $target = cloneUrl($target);
    $sourceParts = parse_url($source);
    $sourceOrigin = $sourceParts['scheme'] . '://' . $sourceParts['host'] . (isset($sourceParts['port']) ? ':' . $sourceParts['port'] : '');
    $sourcePath = rtrim($sourceParts['path'] ?? '', '/');
    $result = [];
    foreach ($rows as $row) {
        $candidate = $row['url'];
        if (strpos($candidate, '://') === false && preg_match('~^[a-zA-Z0-9.-]+(?::[0-9]+)?(?:/.*)?$~', $candidate)) {
            $candidate = $sourceParts['scheme'] . '://' . $candidate;
        }
        try { $old = cloneUrl($candidate); }
        catch (RuntimeException $error) {
            // Placeholder/legacy domains also receive a local URL; never keep a live fallback.
            $result[] = ['id' => $row['id'], 'source' => $row['url'], 'target' => $target . '/__clone/domain-' . substr(hash('sha256', (string) $row['id']), 0, 12)];
            continue;
        }
        $parts = parse_url($old);
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = rtrim($parts['path'] ?? '', '/');
        if ($origin === $sourceOrigin && ($path === $sourcePath || strpos($path, $sourcePath . '/') === 0)) {
            $new = $target . substr($path, strlen($sourcePath));
        } else {
            $new = $target . '/__clone/' . substr(hash('sha256', $origin), 0, 10) . $path;
        }
        $result[] = ['id' => $row['id'], 'source' => $row['url'], 'target' => $new];
    }
    if (!$result || !in_array($target, array_column($result, 'target'), true)) {
        throw new RuntimeException('SOURCE_URL must match a sale channel URL in the copied database.');
    }
    return $result;
}

function configureClone(): void
{
    $data = '/var/lib/shopware-clone';
    $root = $data . '/source';
    $state = json_decode(file_get_contents($data . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($state['phase'] ?? '') !== 'imported') { throw new RuntimeException('Expected a completed raw import.'); }
    $target = cloneUrl(getenv('CLONE_URL') ?: '');
    $source = cloneUrl(getenv('SOURCE_URL') ?: '');
    if ($target === $source) { throw new RuntimeException('Test URL must differ from the live URL.'); }
    $report = json_decode(file_get_contents($data . '/source-report.json'), true, 512, JSON_THROW_ON_ERROR);
    $version = ltrim($report['shopware']['version'], 'v');
    if (version_compare($version, '6.6.0.0', '<') || version_compare($version, '6.8.0.0', '>=')) {
        throw new RuntimeException('Automatic configuration currently supports Shopware 6.6 and 6.7.');
    }
    $env = databaseEnvironment($root, []);
    $backup = $data . '/original-config';
    if (!is_dir($backup)) { mkdir($backup, 0700); }
    $save = static function (string $path, string $contents) use ($root, $backup): void {
        if (is_file($path)) {
            $relative = substr($path, strlen($root) + 1);
            $destination = $backup . '/' . $relative;
            if (!is_dir(dirname($destination))) { mkdir(dirname($destination), 0700, true); }
            if (!is_file($destination)) { copy($path, $destination); }
        }
        if (!is_dir(dirname($path))) { mkdir(dirname($path), 0700, true); }
        if (file_put_contents($path, $contents) === false) { throw new RuntimeException('Cannot write clone configuration.'); }
    };
    // Use only the YAML component, not Composer's application autoloader.
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'Symfony\\Component\\Yaml\\';
        if (strpos($class, $prefix) === 0) {
            $path = $root . '/vendor/symfony/yaml/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) { require_once $path; }
        }
    });
    $yamlClass = 'Symfony\\Component\\Yaml\\Yaml';
    if (!class_exists($yamlClass)) { throw new RuntimeException('Copied vendor lacks Symfony YAML.'); }
    $yamlFiles = [];
    $effective = [];
    foreach (['config/packages/*.yaml', 'config/packages/*.yml', 'config/packages/prod/*.yaml', 'config/packages/prod/*.yml'] as $pattern) {
        foreach (glob($root . '/' . $pattern) as $path) {
            $parsed = $yamlClass::parseFile($path, $yamlClass::PARSE_CUSTOM_TAGS) ?? [];
            if (!is_array($parsed)) { throw new RuntimeException('Unsupported package configuration.'); }
            $yamlFiles[$path] = $parsed;
            $effective = array_replace_recursive($effective, $parsed, $parsed['when@prod'] ?? []);
        }
    }
    $storageOverrides = cloneImportStorage($effective, $env, $root, $data);
    $bool = static function ($value) use ($env): bool {
        if (is_string($value) && preg_match('/^%env\((?:bool:)?([A-Z0-9_]+)\)%$/', $value, $m)) { $value = $env[$m[1]] ?? ''; }
        if (!in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false', '', null], true)) { throw new RuntimeException('Unresolved search enable flag.'); }
        return in_array($value, [true, 1, '1', 'true'], true);
    };
    $search = $bool($effective['elasticsearch']['enabled'] ?? $env['SHOPWARE_ES_ENABLED'] ?? false);
    $adminSearch = $bool($effective['elasticsearch']['administration']['enabled'] ?? $env['SHOPWARE_ADMIN_ES_ENABLED'] ?? false);
    $lock = json_decode(file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    $packages = array_column($lock['packages'], 'version', 'name');
    $engine = 'opensearch';
    $endpoint = 'http://opensearch:9200';
    $pdo = cloneDatabasePdo();
    $dbVersion = $pdo->query('SELECT VERSION()')->fetchColumn();
    $dbUrl = cloneDatabaseUrl((string) $dbVersion);
    $redisMap = [];
    $rewrite = static function (string $value) use (&$redisMap, $endpoint, $dbUrl, $root): string {
        if (preg_match('~^rediss?://~', $value)) {
            if (!isset($redisMap[$value])) {
                if (count($redisMap) >= 15) { throw new RuntimeException('Too many distinct Redis connections.'); }
                $redisMap[$value] = 'redis://redis:6379/' . (count($redisMap) + 1);
            }
            return $redisMap[$value];
        }
        if (preg_match('~^(mysql|mysql2|mariadb)://~', $value)) { return $dbUrl; }
        $sourcePath = getenv('SOURCE_SHOP_PATH');
        if ($sourcePath && strpos($value, $sourcePath) === 0) { return $root . substr($value, strlen($sourcePath)); }
        return $value;
    };
    foreach ($env as $key => $value) {
        if (is_string($value)) { $env[$key] = $rewrite($value); }
    }
    $overrides = [
        'APP_ENV' => 'prod', 'APP_DEBUG' => '0', 'APP_URL' => $target, 'DATABASE_URL' => $dbUrl,
        'MAILER_DSN' => 'smtp://127.0.0.1:1025', 'MAILER_URL' => 'smtp://127.0.0.1:1025',
        'OPENSEARCH_URL' => $endpoint, 'ADMIN_OPENSEARCH_URL' => $endpoint, 'SHOPWARE_ES_HOSTS' => $endpoint, 'SHOPWARE_ADMIN_ES_HOSTS' => $endpoint,
        'SHOPWARE_ES_ENABLED' => $search ? '1' : '0', 'SHOPWARE_ES_INDEXING_ENABLED' => $search ? '1' : '0',
        'SHOPWARE_ADMIN_ES_ENABLED' => $adminSearch ? '1' : '0',
        'SHOPWARE_ES_INDEX_PREFIX' => 'clone', 'SHOPWARE_ADMIN_ES_INDEX_PREFIX' => 'clone-admin',
        'MESSENGER_TRANSPORT_DSN' => 'doctrine://default?auto_setup=false',
        'MESSENGER_TRANSPORT_LOW_PRIORITY_DSN' => 'doctrine://default?auto_setup=false&queue_name=low_priority',
        'MESSENGER_TRANSPORT_FAILURE_DSN' => 'doctrine://default?auto_setup=false&queue_name=failed',
        'LOCK_DSN' => 'flock',
    ];
    $overrides = array_replace($storageOverrides, $overrides);
    // Expose rewritten Redis variables to hardcoded ENV references as well.
    foreach ($env as $key => $value) {
        if (is_string($value) && strpos($value, 'redis://redis:6379/') === 0) { $overrides[$key] = $value; }
    }
    $mapTree = static function ($node) use (&$mapTree, $rewrite) {
        if (is_array($node)) { foreach ($node as $key => $value) { $node[$key] = $mapTree($value); } }
        elseif (is_string($node)) { $node = $rewrite($node); }
        return $node;
    };
    $domainMap = cloneDomainMap($pdo->query('SELECT LOWER(HEX(id)) AS id, url FROM sales_channel_domain ORDER BY url')->fetchAll(PDO::FETCH_ASSOC), $source, $target);
    file_put_contents($data . '/state.json', json_encode(['phase' => 'configuring', 'ready' => false]));
    foreach ($yamlFiles as $path => $config) {
        $config = $mapTree($config);
        foreach (['', 'when@prod'] as $scope) {
            if ($scope === '') { $section =& $config; } else { $section =& $config[$scope]; }
            if (!is_array($section)) { unset($section); continue; }
            foreach (array_keys(cloneLocalFilesystems($target)) as $filesystem) {
                unset($section['shopware']['filesystem'][$filesystem]);
            }
            if (isset($section['shopware']['filesystem']) && $section['shopware']['filesystem'] === []) { unset($section['shopware']['filesystem']); }
            if (isset($section['shopware']['cdn'])) {
                $section['shopware']['cdn']['url'] = '';
                $section['shopware']['cdn']['fastly']['api_key'] = '';
            }
            if (isset($section['doctrine']['dbal'])) {
                $connections = $section['doctrine']['dbal']['connections'] ?? [];
                if (array_diff(array_keys($connections), ['default'])) { throw new RuntimeException('Additional database connections require separate imports.'); }
                $dbal = $section['doctrine']['dbal'];
                foreach (['url', 'host', 'port', 'dbname', 'user', 'password', 'server_version', 'driver', 'unix_socket', 'connections', 'default_connection'] as $key) { unset($dbal[$key]); }
                $section['doctrine']['dbal'] = array_replace($dbal, ['url' => '%env(resolve:DATABASE_URL)%']);
            }
            if (isset($section['elasticsearch']['hosts'])) { $section['elasticsearch']['hosts'] = $endpoint; }
            if (isset($section['elasticsearch']['administration']['hosts'])) { $section['elasticsearch']['administration']['hosts'] = $endpoint; }
            foreach ($section['framework']['messenger']['transports'] ?? [] as $name => $transport) {
                if (!preg_match('/^[a-zA-Z0-9_]+$/', (string) $name)) { throw new RuntimeException('Unsupported queue name.'); }
                $transport = is_array($transport) ? $transport : [];
                unset($transport['options']);
                $transport['dsn'] = 'doctrine://default?auto_setup=false&queue_name=' . $name;
                $section['framework']['messenger']['transports'][$name] = $transport;
            }
            unset($section);
        }
        if (array_key_exists('when@prod', $config) && $config['when@prod'] === null) { unset($config['when@prod']); }
        $save($path, $yamlClass::dump($config, 20, 2));
    }
    $env = array_replace($env, $overrides);
    $save($root . '/.env.local.php', "<?php\nreturn " . var_export($env, true) . ";\n");
    $runtimeShell = "#!/bin/bash\n";
    foreach ($overrides as $key => $value) {
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) { throw new RuntimeException('Invalid runtime variable name.'); }
        $runtimeShell .= 'export ' . $key . '=' . escapeshellarg($value) . "\n";
    }
    file_put_contents($data . '/runtime.sh', $runtimeShell);
    $extra = [
        'framework' => ['trusted_proxies' => 'REMOTE_ADDR', 'trusted_headers' => ['x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-port'], 'mailer' => ['dsn' => 'smtp://127.0.0.1:1025'], 'session' => ['handler_id' => null]],
        'shopware' => ['filesystem' => cloneLocalFilesystems($target), 'cdn' => ['url' => '', 'fastly' => ['api_key' => '']], 'admin_worker' => ['enable_admin_worker' => false]],
    ];
    if (isset($packages['shopware/elasticsearch'])) {
        $extra['elasticsearch'] = ['hosts' => $endpoint, 'enabled' => $search, 'indexing_enabled' => $search, 'index_prefix' => 'clone', 'index_settings' => ['number_of_replicas' => 0], 'administration' => ['hosts' => $endpoint, 'enabled' => $adminSearch, 'index_prefix' => 'clone-admin', 'index_settings' => ['number_of_replicas' => 0]]];
    }
    $save($root . '/config/packages/prod/zzzz_clone.yaml', $yamlClass::dump($extra, 20, 2));
    $save($root . '/public/.htaccess', clonePublicHtaccess() . "\n");
    foreach (['.htaccess', 'public/.htaccess.watch', 'public/.user.ini'] as $name) {
        $path = $root . '/' . $name;
        if (!is_file($path)) { continue; }
        if ($name === '.htaccess') {
            $save($path, "# Live hosting rules are disabled in the disposable clone.\n");
            continue;
        }
        $contents = file_get_contents($path);
        if (substr($name, -9) !== '.user.ini') {
            $contents = preg_replace('/^\s*(?:AddHandler|SetHandler|Action)\s+[^\r\n]*php[^\r\n]*$/mi', '# PHP handler supplied by clone runtime', $contents);
        }
        $contents = str_replace($source, $target, $contents);
        if (getenv('SOURCE_SHOP_PATH')) { $contents = str_replace(getenv('SOURCE_SHOP_PATH'), $root, $contents); }
        $save($path, $contents);
    }
    $pdo->beginTransaction();
    foreach ($domainMap as $row) {
        $stmt = $pdo->prepare('UPDATE sales_channel_domain SET url=? WHERE id=UNHEX(?)');
        $stmt->execute([$row['target'], $row['id']]);
    }
    $pdo->exec("UPDATE system_config SET configuration_value='{\"_value\":\"\"}' WHERE configuration_key='core.mailerSettings.emailAgent'");
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['messenger_messages', 'elasticsearch_index_task'] as $table) {
        if (in_array($table, $tables, true)) { $pdo->exec('DELETE FROM `' . $table . '`'); }
    }
    if (in_array('scheduled_task', $tables, true)) { $pdo->exec("UPDATE scheduled_task SET status='scheduled' WHERE status IN ('queued','running')"); }
    $pdo->commit();
    file_put_contents($data . '/domain-map.json', json_encode($domainMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($data . '/runtime.json', json_encode(['php' => $report['php']['selected'], 'url' => $target, 'search' => $search, 'admin_search' => $adminSearch, 'search_endpoint' => $endpoint, 'engine' => $engine]));
    file_put_contents($data . '/state.json', json_encode(['schema_version' => 1, 'phase' => 'configured', 'ready' => false, 'database' => 'shopware_clone']));
    echo "Local configuration applied; queued live messages removed.\n";
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { configureClone(); }
    catch (Throwable $error) {
        // Details stay private; errors from parsers/DB can contain credentials.
        file_put_contents('/var/lib/shopware-clone/configure-error.log', (string) $error);
        fwrite(STDERR, "Clone configuration failed. Details: /var/lib/shopware-clone/configure-error.log\n");
        exit(1);
    }
}
