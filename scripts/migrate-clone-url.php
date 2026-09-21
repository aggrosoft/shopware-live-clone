<?php
declare(strict_types=1);

require_once __DIR__ . '/clone-database.php';

function normalizedCloneUrl(string $url): string
{
    $url = rtrim($url, '/');
    $parts = parse_url($url);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || ($parts['port'] ?? null) !== 80) {
        return $url;
    }
    $host = str_contains($parts['host'], ':') ? '[' . $parts['host'] . ']' : $parts['host'];
    return 'https://' . $host . ($parts['path'] ?? '');
}

function migrateCloneUrl(): bool
{
    $data = '/var/lib/shopware-clone';
    $runtimePath = $data . '/runtime.json';
    if (!is_file($runtimePath)) { return false; }

    $runtime = json_decode(file_get_contents($runtimePath), true, 512, JSON_THROW_ON_ERROR);
    $old = rtrim((string) ($runtime['url'] ?? ''), '/');
    $requested = rtrim((string) getenv('CLONE_URL'), '/');
    $target = normalizedCloneUrl($requested);
    if ($old === $target) { return false; }
    if (normalizedCloneUrl($old) !== $target) {
        throw new RuntimeException('CLONE_URL changed. Use a new clone for a different URL.');
    }

    $pdo = cloneDatabasePdo();
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            "UPDATE sales_channel_domain SET url=CONCAT(?, SUBSTRING(url, CHAR_LENGTH(?) + 1)) " .
            "WHERE url=? OR url LIKE CONCAT(?, '/%')"
        );
        $statement->execute([$target, $old, $old, $old]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }

    $root = $data . '/source';
    foreach ([
        $data . '/runtime.sh',
        $data . '/domain-map.json',
        $root . '/.env.local.php',
        $root . '/config/packages/prod/zzzz_clone.yaml',
        $root . '/public/.htaccess.watch',
        $root . '/public/.user.ini',
    ] as $path) {
        if (!is_file($path)) { continue; }
        $contents = file_get_contents($path);
        if ($contents === false) { throw new RuntimeException('Cannot read clone configuration: ' . $path); }
        $contents = str_replace($old, $target, $contents);
        if (file_put_contents($path, $contents) === false) { throw new RuntimeException('Cannot update clone configuration: ' . $path); }
    }

    $runtime['url'] = $target;
    file_put_contents($runtimePath, json_encode($runtime, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    file_put_contents($data . '/url-migrated', $old . " -> " . $target . "\n");
    echo "Normalized legacy Coolify clone URL: $old -> $target\n";
    return true;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try { migrateCloneUrl(); }
    catch (Throwable $error) {
        file_put_contents('/var/lib/shopware-clone/url-migration-error.log', (string) $error);
        fwrite(STDERR, "Clone URL migration failed. Details: /var/lib/shopware-clone/url-migration-error.log\n");
        exit(1);
    }
}
