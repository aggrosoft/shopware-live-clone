<?php
declare(strict_types=1);

function cloneStorageValue($value, array $env, string $root)
{
    if (is_array($value)) {
        foreach ($value as $key => $item) { $value[$key] = cloneStorageValue($item, $env, $root); }
        return $value;
    }
    if (!is_string($value)) { return $value; }
    if (preg_match('/^%env\((?:(bool|string|resolve):)?([A-Z0-9_]+)\)%$/', $value, $match)) {
        if (!array_key_exists($match[2], $env)) { throw new RuntimeException('Missing storage environment variable: ' . $match[2]); }
        return $match[1] === 'bool' ? filter_var($env[$match[2]], FILTER_VALIDATE_BOOLEAN) : $env[$match[2]];
    }
    return str_replace('%kernel.project_dir%', $root, $value);
}

function cloneLocalFilesystems(): array
{
    $result = [];
    foreach (['public' => 'public', 'private' => 'files', 'temp' => 'var', 'theme' => 'public', 'asset' => 'public', 'sitemap' => 'public'] as $name => $directory) {
        $result[$name] = ['type' => 'local', 'config' => ['root' => '%kernel.project_dir%/' . $directory]];
        if ($name !== 'temp') { $result[$name]['visibility'] = $name === 'private' ? 'private' : 'public'; }
        if (!in_array($name, ['private', 'temp'], true)) { $result[$name]['url'] = ''; }
    }
    return $result;
}

function cloneStorageEnvNames($value): array
{
    $names = [];
    if (is_array($value)) {
        foreach ($value as $item) { $names = array_merge($names, cloneStorageEnvNames($item)); }
    } elseif (is_string($value)) {
        preg_match_all('/%env\((?:[a-z_:]+:)?([A-Z0-9_]+)\)%/', $value, $matches);
        $names = $matches[1];
    }
    return array_values(array_unique($names));
}

function cloneCopyS3(array $config, string $destination, string $data, bool $public): void
{
    foreach (['bucket', 'region'] as $key) {
        if (empty($config[$key]) || !is_string($config[$key])) { throw new RuntimeException('Missing S3 ' . $key); }
    }
    $settings = ['type' => 's3', 'provider' => 'Other', 'env_auth' => 'false', 'region' => $config['region'], 'no_check_bucket' => 'true'];
    if (!empty($config['session_token'])) { $settings['session_token'] = $config['session_token']; }
    if (!empty($config['endpoint'])) { $settings['endpoint'] = $config['endpoint']; }
    if (isset($config['use_path_style_endpoint'])) { $settings['force_path_style'] = $config['use_path_style_endpoint'] ? 'true' : 'false'; }
    if (!empty($config['credentials'])) {
        $settings['access_key_id'] = $config['credentials']['key'] ?? '';
        $settings['secret_access_key'] = $config['credentials']['secret'] ?? '';
    }
    $ini = "[source]\n";
    foreach ($settings as $key => $value) {
        if (!is_scalar($value) || strpbrk((string) $value, "\r\n") !== false) { throw new RuntimeException('Invalid S3 setting.'); }
        $ini .= $key . ' = ' . $value . "\n";
    }
    $remote = 'source:' . $config['bucket'] . '/' . trim((string) ($config['root'] ?? ''), '/');
    $file = tempnam($data, 's3-read-');
    if ($file === false) { throw new RuntimeException('Cannot create temporary S3 read configuration.'); }
    try {
        chmod($file, 0600);
        file_put_contents($file, $ini);
        if (!is_dir($destination) && !mkdir($destination, 0700, true)) { throw new RuntimeException('Cannot create local media directory.'); }
        $command = ['rclone', '--config', $file, 'copy', $remote, $destination,
            '--transfers', '8', '--checkers', '8', '--retries', '3', '--stats', '15s', '--stats-one-line',
            '--log-file', $data . '/storage-import.log', '--log-level', 'NOTICE'];
        if ($public) { array_push($command, '--include', '/media/**', '--include', '/thumbnail/**'); }
        // Source is only LIST/HEAD/GET. No sync, delete, move or source writes.
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $data . '/storage-import.log', 'a'], 2 => ['file', $data . '/storage-import.log', 'a']], $pipes);
        if (!is_resource($process) || proc_close($process) !== 0) {
            throw new RuntimeException('S3 read copy failed. Details: /var/lib/shopware-clone/storage-import.log');
        }
    } finally {
        unlink($file);
    }
}

function cloneImportStorage(array $effective, array $env, string $root, string $data): array
{
    $filesystems = $effective['shopware']['filesystem'] ?? [];
    foreach (['public' => 'public', 'private' => 'files'] as $name => $directory) {
        $storage = cloneStorageValue($filesystems[$name] ?? ['type' => 'local'], $env, $root);
        $type = $storage['type'] ?? 'local';
        if ($type === 'amazon-s3') {
            echo "Copying $name S3 storage into the clone (read-only source access)...\n";
            $config = $storage['config'] ?? [];
            if (empty($config['credentials']) && !empty($env['AWS_ACCESS_KEY_ID']) && !empty($env['AWS_SECRET_ACCESS_KEY'])) {
                $config['credentials'] = ['key' => $env['AWS_ACCESS_KEY_ID'], 'secret' => $env['AWS_SECRET_ACCESS_KEY']];
            }
            if (!empty($env['AWS_SESSION_TOKEN'])) { $config['session_token'] = $env['AWS_SESSION_TOKEN']; }
            cloneCopyS3($config, $root . '/' . $directory, $data, $name === 'public');
            echo "Local $name storage copy complete.\n";
        } elseif ($type !== 'local') {
            throw new RuntimeException('Unsupported source storage type for ' . $name . ': ' . (string) $type);
        }
    }
    $names = cloneStorageEnvNames($filesystems);
    $names = array_merge($names, cloneStorageEnvNames($effective['shopware']['cdn']['url'] ?? ''), cloneStorageEnvNames($effective['shopware']['cdn']['fastly']['api_key'] ?? ''));
    foreach (['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_SESSION_TOKEN'] as $name) {
        if (array_key_exists($name, $env)) { $names[] = $name; }
    }
    return array_fill_keys(array_unique($names), '');
}
