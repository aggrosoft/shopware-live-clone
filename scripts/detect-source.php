<?php
// Read-only inspection: no Shopware boot, autoloader, DB connection or remote writes.
declare(strict_types=1);

function inspectShop(string $input): array
{
    $root = realpath($input);
    if ($root === false || !is_file($root . '/composer.lock') || !is_file($root . '/bin/console')) {
        throw new RuntimeException('Not a Shopware project root.');
    }
    $read = static function (string $path): string {
        if (!is_readable($path) || filesize($path) > 8 * 1024 * 1024) {
            throw new RuntimeException('Source file unreadable or too large.');
        }
        $text = file_get_contents($path);
        if ($text === false) { throw new RuntimeException('Read failed.'); }
        return $text;
    };
    $lock = json_decode($read($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    $version = null;
    $phpRequirement = null;
    foreach ($lock['packages'] ?? [] as $package) {
        if (($package['name'] ?? '') === 'shopware/core') {
            $version = $package['version'] ?? null;
            $phpRequirement = $package['require']['php'] ?? null;
        }
    }
    if (!is_string($version) || !preg_match('/^v?6\./', $version)) {
        throw new RuntimeException('No supported Shopware 6 core in composer.lock.');
    }
    $warnings = [];
    $env = [];
    $envFiles = [];
    // Parse only literal dotenv values. Never source or execute a live config file.
    $loadEnv = static function (string $name) use (&$env, &$envFiles, &$warnings, $root, $read): void {
        if (!is_file($root . '/' . $name)) { return; }
        $envFiles[] = $name;
        foreach (preg_split('/\r?\n/', $read($root . '/' . $name)) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') { continue; }
            if (!preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $match)) {
                $warnings[] = 'Unparsed dotenv syntax; effective configuration requires verification.';
                continue;
            }
            $value = trim($match[2]);
            if (preg_match('/^\x27([^\x27]*)\x27\s*(?:#.*)?$/', $value, $quoted)) {
                $env[$match[1]] = $quoted[1];
            } elseif (preg_match('/^"([^"\\\\]*)"\s*(?:#.*)?$/', $value, $quoted) && strpos($quoted[1], '$') === false) {
                $env[$match[1]] = $quoted[1];
            } elseif (preg_match('/^[^\s\x27"$\\\\]*\s*(?:#.*)?$/', $value) && strpos($value, '$') === false) {
                $env[$match[1]] = preg_replace('/\s+#.*$/', '', $value);
            } else {
                $env[$match[1]] = null;
                $warnings[] = 'Dynamic or multiline dotenv value; effective configuration requires verification.';
            }
        }
    };
    $loadEnv('.env');
    $loadEnv('.env.local');
    $environment = $env['APP_ENV'] ?? 'prod';
    if (preg_match('/^[a-zA-Z0-9_-]+$/', $environment)) {
        $loadEnv('.env.' . $environment);
        $loadEnv('.env.' . $environment . '.local');
    }
    $compiled = is_file($root . '/.env.local.php');
    if ($compiled) { $warnings[] = 'Compiled .env.local.php exists; it was not executed. Dotenv findings may be overridden.'; }

    // Scan inherited .htaccess from filesystem root towards the document root.
    $directories = [$root . '/public'];
    for ($dir = $root; ; $dir = dirname($dir)) {
        $directories[] = $dir;
        if (dirname($dir) === $dir) { break; }
    }
    $handlerVersion = null;
    $handlerFile = null;
    foreach (array_reverse($directories) as $dir) {
        $file = $dir . '/.htaccess';
        if (!is_readable($file)) { continue; }
        foreach (preg_split('/\r?\n/', $read($file)) as $line) {
            // Hetzner: AddHandler application/x-httpd-php83 .php (or php8.3).
            if (preg_match('/^\s*(?:AddHandler|SetHandler|Action)\s+[^#]*?php[-_]?([578])\.?([0-9])(?:[^0-9]|$)/i', $line, $match)) {
                $handlerVersion = $match[1] . '.' . $match[2];
                $handlerFile = $file;
            }
        }
    }
    $selected = $handlerVersion ?? PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $warnings[] = $handlerVersion === null
        ? 'Web PHP not verified; using CLI fallback.'
        : 'PHP handler inferred from .htaccess; conditional directives and server overrides are not evaluated.';

    $service = static function (array $keys) use ($env, $compiled): array {
        $present = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $env) && $env[$key] !== '') { $present[] = $key; }
        }
        return ['configured' => count($present) ? true : null, 'variables' => $present, 'effective_verified' => false];
    };
    $redisKeys = array_values(array_filter(array_keys($env), static function ($key) use ($env): bool {
        return stripos($key, 'REDIS') !== false || (is_string($env[$key]) && preg_match('~^rediss?://~', $env[$key]) === 1);
    }));
    $database = $service(['DATABASE_URL']);
    $database['driver'] = null;
    if (is_string($env['DATABASE_URL'] ?? null)) {
        $scheme = parse_url($env['DATABASE_URL'], PHP_URL_SCHEME);
        $database['driver'] = in_array($scheme, ['mysql', 'mysql2', 'mariadb'], true) ? $scheme : 'unknown';
    }
    $customConfig = [];
    $iterator = is_dir($root . '/config') ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/config', FilesystemIterator::SKIP_DOTS)) : [];
    foreach ($iterator as $file) {
        if ($file->isLink() || !$file->isFile() || !preg_match('/\.(yaml|yml|xml|php)$/', $file->getFilename())) { continue; }
        if (preg_match('/redis|elastic|opensearch|DATABASE_URL|mailer|messenger/i', $read($file->getPathname()))) {
            $customConfig[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
    if ($customConfig) { $warnings[] = 'Service references in config files found; inspect before rewriting connections.'; }
    $warnings[] = 'Server environment and plugin configuration in the database are not inspected in this step.';
    return [
        'schema_version' => 1,
        'shopware' => ['version' => $version, 'php_requirement' => $phpRequirement, 'vendor_present' => is_file($root . '/vendor/autoload.php')],
        'php' => ['cli' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, 'selected' => $selected, 'source' => $handlerFile === null ? 'cli' : '.htaccess', 'handler_file' => $handlerFile, 'supported_by_image' => in_array($selected, ['8.2', '8.3', '8.4', '8.5'], true)],
        'tools' => ['rsync' => is_executable('/usr/bin/rsync'), 'mysql_dump' => is_executable('/usr/bin/mysqldump') || is_executable('/usr/bin/mariadb-dump')],
        'configuration' => ['dotenv_files' => $envFiles, 'compiled_env_present' => $compiled, 'service_config_files' => $customConfig],
        'services' => [
            'database' => $database,
            'search' => $service(['SHOPWARE_ES_HOSTS', 'OPENSEARCH_URL', 'ADMIN_OPENSEARCH_URL', 'SHOPWARE_ADMIN_ES_HOSTS']),
            'redis' => $service($redisKeys),
            'mail' => $service(['MAILER_DSN', 'MAILER_URL']),
        ],
        'warnings' => array_values(array_unique($warnings)),
    ];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ || ($_SERVER['SCRIPT_FILENAME'] ?? '') === '') {
    try {
        $path = base64_decode($argv[1] ?? '', true);
        if ($path === false || $path === '' || $path[0] !== '/') { throw new RuntimeException('Invalid source path.'); }
        echo json_encode(inspectShop($path), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (Throwable $error) {
        // Never print exception details: config parsing errors can contain credentials.
        fwrite(STDERR, "Shopware source inspection failed.\n");
        exit(1);
    }
}
