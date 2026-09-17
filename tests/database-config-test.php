<?php
declare(strict_types=1);
require __DIR__ . '/../scripts/source-database.php';
$check = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$reject = static function (callable $fn) use ($check): void {
    $rejected = false;
    try { $fn(); } catch (RuntimeException $error) { $rejected = true; }
    $check($rejected, 'Unsafe or unsupported configuration accepted.');
};
$values = ['APP_ENV' => 'prod', 'DATABASE_URL' => 'mysql://name:p%40ss%3Aword@localhost:3307/shop?serverVersion=8.0&charset=utf8mb4', 'QUOTED' => "a\\'b\\c", 'NULL' => null];
$parsed = literalEnvironment("<?php\nreturn " . var_export($values, true) . ';');
$check($parsed === $values, 'Compiled environment literal roundtrip.');
$connection = databaseConnection($parsed);
$check($connection['password'] === 'p@ss:word' && $connection['port'] === '3307', 'Encoded password and port.');
$reject(static function (): void { literalEnvironment('<?php return ["DATABASE_URL" => getenv("SECRET")];'); });
$reject(static function (): void { literalEnvironment('<?php return ["APP_ENV" => "prod"]; unlink("/tmp/never");'); });
$reject(static function (): void { databaseConnection(['DATABASE_URL' => 'mysql://u:p@host/-danger']); });
$reject(static function (): void { databaseConnection(['DATABASE_URL' => 'mysql://u:p@host/shop?sslmode=require']); });
$fixture = sys_get_temp_dir() . '/clone-db-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700);
try {
    file_put_contents($fixture . '/.env', "APP_ENV=prod\nDATABASE_URL='mysql://base:p@host/base'\n");
    file_put_contents($fixture . '/.env.local', "DATABASE_URL='mysql://local:p@host/local'\n");
    file_put_contents($fixture . '/.env.prod.local', 'DATABASE_URL="mysql://${DB_USER}:p@host/final"');
    $env = databaseEnvironment($fixture, ['DB_USER' => 'effective']);
    $check(databaseConnection($env)['user'] === 'effective', 'Simple interpolation and dotenv precedence.');
    $env = databaseEnvironment($fixture, ['DATABASE_URL' => 'mysql://server:p@host/server']);
    $check(databaseConnection($env)['name'] === 'server', 'Server environment must win.');
    $reject(static function () use ($fixture): void { databaseConnection(databaseEnvironment($fixture, [])); });
    file_put_contents($fixture . '/.env.local.php', '<?php return ' . var_export($values, true) . ';');
    $check(databaseConnection(databaseEnvironment($fixture, []))['name'] === 'shop', 'Compiled environment must win.');
    $reject(static function () use ($fixture): void { databaseEnvironment($fixture, ['APP_ENV' => 'dev']); });
    echo "Database configuration tests: OK\n";
} finally {
    foreach (['.env', '.env.local', '.env.prod.local', '.env.local.php'] as $name) {
        if (is_file($fixture . '/' . $name)) { unlink($fixture . '/' . $name); }
    }
    rmdir($fixture);
}
