<?php
declare(strict_types=1);

function cloneDatabaseHost(): string
{
    $host = getenv('CLONE_DATABASE_HOST') ?: '127.0.0.1';
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]*$/', $host)) {
        throw new RuntimeException('Invalid clone database host.');
    }
    return $host;
}

function cloneDatabasePort(): int
{
    $port = getenv('CLONE_DATABASE_PORT') ?: '3306';
    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('Invalid clone database port.');
    }
    return (int) $port;
}

function cloneDatabasePdo(?string $database = 'shopware_clone'): PDO
{
    $dsn = 'mysql:host=' . cloneDatabaseHost() . ';port=' . cloneDatabasePort() . ';charset=utf8mb4';
    if ($database !== null) { $dsn .= ';dbname=' . $database; }
    return new PDO($dsn, 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function cloneDatabaseUrl(string $serverVersion): string
{
    return 'mysql://root:root@' . cloneDatabaseHost() . ':' . cloneDatabasePort()
        . '/shopware_clone?charset=utf8mb4&serverVersion=' . rawurlencode($serverVersion);
}
