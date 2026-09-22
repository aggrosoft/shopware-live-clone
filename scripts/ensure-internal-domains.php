<?php
declare(strict_types=1);

require_once __DIR__ . '/configure-clone.php';
require_once __DIR__ . '/clone-database.php';

function ensurePersistedCloneInternalDomains(): bool
{
    $data = '/var/lib/shopware-clone';
    $runtimePath = $data . '/runtime.json';
    $domainMapPath = $data . '/domain-map.json';

    if (!is_file($runtimePath) || !is_file($domainMapPath)) {
        return false;
    }

    $runtime = json_decode(file_get_contents($runtimePath), true, 512, JSON_THROW_ON_ERROR);
    $domainMap = json_decode(file_get_contents($domainMapPath), true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($domainMap)) {
        throw new RuntimeException('Clone domain map is invalid.');
    }

    $externalBase = cloneUrl((string) ($runtime['url'] ?? ''));
    $internalBase = cloneUrl(getenv('INTERNAL_STOREFRONT_ORIGIN') ?: 'http://shop');
    $pdo = cloneDatabasePdo();
    $changed = false;

    $pdo->beginTransaction();
    try {
        foreach ($domainMap as &$row) {
            if (!is_array($row) || empty($row['id']) || empty($row['target'])) {
                throw new RuntimeException('Clone domain map contains an invalid entry.');
            }

            $internalUrl = internalCloneDomainUrl((string) $row['target'], $externalBase, $internalBase);
            if (cloneInternalSalesChannelDomain($pdo, (string) $row['id'], $internalUrl)) {
                $changed = true;
            }
            $row['internal'] = $internalUrl;
        }
        unset($row);

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    file_put_contents(
        $domainMapPath,
        json_encode($domainMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );

    if ($changed) {
        file_put_contents($data . '/internal-domains-migrated', "1\n");
        echo "Added internal sales-channel domains to persisted clone.\n";
    }

    return $changed;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        ensurePersistedCloneInternalDomains();
    } catch (Throwable $error) {
        file_put_contents('/var/lib/shopware-clone/internal-domains-error.log', (string) $error);
        fwrite(STDERR, "Internal domain migration failed. Details: /var/lib/shopware-clone/internal-domains-error.log\n");
        exit(1);
    }
}
