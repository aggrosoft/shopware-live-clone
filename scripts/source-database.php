<?php
declare(strict_types=1);

// Parse Symfony's dumped environment as data. Never include/eval source PHP.
function literalEnvironment(string $text): array
{
    $tokens = array_values(array_filter(token_get_all($text), static function ($token): bool {
        return !is_array($token) || !in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }));
    $i = 0;
    $expect = static function ($expected) use (&$tokens, &$i): void {
        $token = $tokens[$i++] ?? null;
        if (is_int($expected) ? (!is_array($token) || $token[0] !== $expected) : $token !== $expected) {
            throw new RuntimeException('Unsupported compiled environment.');
        }
    };
    $string = static function ($token): string {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) { throw new RuntimeException('Not a literal string.'); }
        $value = substr($token[1], 1, -1);
        return $token[1][0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $value) : stripcslashes($value);
    };
    $expect(T_RETURN);
    if (($tokens[$i] ?? null) === '[') { $expect('['); $end = ']'; }
    else { $expect(T_ARRAY); $expect('('); $end = ')'; }
    $values = [];
    while (($tokens[$i] ?? null) !== $end) {
        $key = $string($tokens[$i++] ?? null);
        $expect(T_DOUBLE_ARROW);
        $token = $tokens[$i++] ?? null;
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) { $value = $string($token); }
        elseif (is_array($token) && $token[0] === T_LNUMBER) { $value = $token[1]; }
        elseif (is_array($token) && $token[0] === T_STRING && in_array(strtolower($token[1]), ['true', 'false', 'null'], true)) {
            $value = ['true' => '1', 'false' => '', 'null' => null][strtolower($token[1])];
        } else { throw new RuntimeException('Nonliteral compiled value.'); }
        $values[$key] = $value;
        if (($tokens[$i] ?? null) === $end) { break; }
        $expect(',');
    }
    $expect($end);
    $expect(';');
    if ($i !== count($tokens)) { throw new RuntimeException('Unexpected code after environment array.'); }
    return $values;
}

function databaseEnvironment(string $root, array $server): array
{
    $values = [];
    $read = static function (string $path): string {
        if (!is_readable($path) || filesize($path) > 8 * 1024 * 1024) { throw new RuntimeException('Cannot read environment.'); }
        $content = file_get_contents($path);
        if ($content === false) { throw new RuntimeException('Cannot read environment.'); }
        return $content;
    };
    if (is_file($root . '/.env.local.php')) {
        $values = literalEnvironment($read($root . '/.env.local.php'));
        if (isset($server['APP_ENV'], $values['APP_ENV']) && $server['APP_ENV'] !== $values['APP_ENV']) {
            throw new RuntimeException('Compiled environment does not match runtime.');
        }
    } else {
        $load = static function (string $name) use ($root, &$values, $server, $read): void {
            if (!is_file($root . '/' . $name)) { return; }
            foreach (preg_split('/\r?\n/', $read($root . '/' . $name)) as $line) {
                if (!preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $match)) { continue; }
                $raw = trim($match[2]);
                $interpolate = true;
                if (preg_match('/^\x27([^\x27]*)\x27\s*(?:#.*)?$/', $raw, $quoted)) {
                    $value = $quoted[1]; $interpolate = false;
                } elseif (preg_match('/^"([^"\\\\]*)"\s*(?:#.*)?$/', $raw, $quoted)) {
                    $value = $quoted[1];
                } elseif (preg_match('/^[^\s\x27"\\\\]*\s*(?:#.*)?$/', $raw)) {
                    $value = preg_replace('/\s+#.*$/', '', $raw);
                } else { $values[$match[1]] = null; continue; }
                if ($interpolate && strpos($value, '$') !== false) {
                    $unresolved = false;
                    $value = preg_replace_callback('/\$\{([A-Za-z_][A-Za-z0-9_]*)\}|\$([A-Za-z_][A-Za-z0-9_]*)/', static function ($ref) use (&$unresolved, $server, $values): string {
                        $name = !empty($ref[1]) ? $ref[1] : $ref[2];
                        $replacement = $server[$name] ?? $values[$name] ?? null;
                        if (!is_string($replacement)) { $unresolved = true; return ''; }
                        return $replacement;
                    }, $value);
                    if ($unresolved || strpos($value, '$') !== false) { $value = null; }
                }
                $values[$match[1]] = $value;
            }
        };
        $load('.env');
        $loadEnv = $server['APP_ENV'] ?? $values['APP_ENV'] ?? 'prod';
        if ($loadEnv !== 'test') { $load('.env.local'); }
        $env = $server['APP_ENV'] ?? $values['APP_ENV'] ?? 'prod';
        if (!is_string($env) || !preg_match('/^[a-zA-Z0-9_-]+$/', $env)) { throw new RuntimeException('Unknown APP_ENV.'); }
        $load('.env.' . $env);
        $load('.env.' . $env . '.local');
    }
    return array_replace($values, $server);
}

function databaseConnection(array $env): array
{
    $url = $env['DATABASE_URL'] ?? null;
    if (!is_string($url) || $url === '') { throw new RuntimeException('DATABASE_URL cannot be resolved.'); }
    $dsn = parse_url($url);
    if ($dsn === false || !in_array($dsn['scheme'] ?? '', ['mysql', 'mysql2', 'mariadb'], true) || isset($dsn['fragment'])) {
        throw new RuntimeException('Unsupported DATABASE_URL.');
    }
    parse_str($dsn['query'] ?? '', $query);
    if (array_diff(array_keys($query), ['serverVersion', 'charset', 'unix_socket'])) { throw new RuntimeException('Unsupported database connection options.'); }
    $name = rawurldecode(ltrim($dsn['path'] ?? '', '/'));
    if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_$.-]*$/', $name)) { throw new RuntimeException('Unsupported database name.'); }
    $result = [
        'host' => $dsn['host'] ?? 'localhost', 'port' => (string) ($dsn['port'] ?? 3306),
        'user' => rawurldecode($dsn['user'] ?? ''), 'password' => rawurldecode($dsn['pass'] ?? ''), 'name' => $name,
        'socket' => $query['unix_socket'] ?? null,
    ];
    if ($result['user'] === '') { throw new RuntimeException('Missing database user.'); }
    if ($result['socket'] !== null && (!is_string($result['socket']) || strpos($result['socket'], '/') !== 0)) { throw new RuntimeException('Invalid database socket.'); }
    foreach ($result as $value) { if (is_string($value) && strpos($value, "\0") !== false) { throw new RuntimeException('Invalid connection value.'); } }
    return $result;
}

function dbProcess(array $command, array $environment, bool $stream = false): string
{
    $descriptor = [0 => ['file', '/dev/null', 'r'], 1 => $stream ? STDOUT : ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']];
    $process = proc_open($command, $descriptor, $pipes, null, $environment);
    if (!is_resource($process)) { throw new RuntimeException('Database client failed.'); }
    $output = '';
    if (!$stream) { $output = stream_get_contents($pipes[1]); fclose($pipes[1]); }
    if (proc_close($process) !== 0) { throw new RuntimeException('Database client failed.'); }
    return $output;
}

function dumpSource(string $root): void
{
    if (!is_file($root . '/composer.lock') || !is_file($root . '/bin/console')) { throw new RuntimeException('Invalid shop root.'); }
    $server = getenv();
    $connection = databaseConnection(databaseEnvironment($root, $server));
    $client = is_executable('/usr/bin/mariadb') ? '/usr/bin/mariadb' : '/usr/bin/mysql';
    $dump = is_executable('/usr/bin/mariadb-dump') ? '/usr/bin/mariadb-dump' : '/usr/bin/mysqldump';
    if (!is_executable($client) || !is_executable($dump)) { throw new RuntimeException('Missing database tools.'); }
    $environment = array_replace($server, ['MYSQL_PWD' => $connection['password'], 'MYSQL_TEST_LOGIN_FILE' => '/dev/null']);
    $options = ['--no-defaults', '--user=' . $connection['user']];
    if ($connection['socket'] !== null) { $options[] = '--protocol=SOCKET'; $options[] = '--socket=' . $connection['socket']; }
    else { $options[] = '--host=' . $connection['host']; $options[] = '--port=' . $connection['port']; $options[] = '--protocol=TCP'; }
    $engineQuery = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND TABLE_TYPE='BASE TABLE' AND ENGINE <> 'InnoDB'";
    $count = trim(dbProcess(array_merge([$client], $options, ['--batch', '--skip-column-names', '--database=' . $connection['name'], '--execute=' . $engineQuery]), $environment));
    if ($count !== '0') { throw new RuntimeException('Nontransactional tables cannot be dumped consistently without locking.'); }
    $version = dbProcess([$dump, '--no-defaults', '--version'], $environment);
    $flags = ['--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', '--hex-blob', '--skip-comments', '--default-character-set=utf8mb4'];
    if (stripos($version, 'MariaDB') === false) {
        $flags[] = '--set-gtid-purged=OFF';
        if (preg_match('/\bVer\s+8\./', $version)) { $flags[] = '--column-statistics=0'; }
    }
    dbProcess(array_merge([$dump], $options, $flags, ['--', $connection['name']]), $environment, true);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ || ($_SERVER['SCRIPT_FILENAME'] ?? '') === '') {
    try {
        $root = base64_decode($argv[1] ?? '', true);
        if ($root === false || $root === '' || $root[0] !== '/') { throw new RuntimeException('Invalid source path.'); }
        dumpSource($root);
    } catch (Throwable $error) {
        fwrite(STDERR, "Source database dump failed: check configuration, client tools, privileges and transactional tables.\n");
        exit(1);
    }
}
