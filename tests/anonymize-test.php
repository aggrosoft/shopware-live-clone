<?php
// Only called in the disposable SSH/MySQL integration-test container.
declare(strict_types=1);
$pdo = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
function check(bool $condition): void { if (!$condition) { throw new RuntimeException('Anonymization assertion failed.'); } }
if (($argv[1] ?? '') === 'seed') {
    $pdo->exec('USE live_fixture');
    $pdo->exec('CREATE TABLE customer (id BINARY(16) PRIMARY KEY, email VARCHAR(255) UNIQUE, first_name VARCHAR(255), last_name VARCHAR(255), company VARCHAR(255), birthday DATE, vat_ids JSON, remote_address VARCHAR(255)) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO customer VALUES (UNHEX(REPEAT('11',16)), 'private@example.org', 'Private', 'Person', 'Private Company', '1980-01-01', '[\"DE123\"]', '192.0.2.1')");
    $pdo->exec('CREATE TABLE customer_address (id BINARY(16) PRIMARY KEY, customer_id BINARY(16), first_name VARCHAR(255), last_name VARCHAR(255), street VARCHAR(255), zipcode VARCHAR(50), city VARCHAR(255), phone_number VARCHAR(40), additional_address_line1 VARCHAR(255), country_id BINARY(16)) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO customer_address VALUES (UNHEX(REPEAT('22',16)), UNHEX(REPEAT('11',16)), 'Private', 'Person', 'Private Street 23', '98765', 'Private City', '123456', 'Private Additional', UNHEX(REPEAT('33',16)))");
    $pdo->exec('CREATE TABLE order_customer (id BINARY(16), version_id BINARY(16), customer_id BINARY(16), email VARCHAR(255), first_name VARCHAR(255), last_name VARCHAR(255), PRIMARY KEY (id,version_id)) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO order_customer VALUES (UNHEX(REPEAT('44',16)), UNHEX(REPEAT('55',16)), UNHEX(REPEAT('11',16)), 'private@example.org', 'Private', 'Person'), (UNHEX(REPEAT('44',16)), UNHEX(REPEAT('66',16)), NULL, 'guest@example.org', 'Guest', 'Person')");
    $pdo->exec('CREATE TABLE order_address LIKE customer_address');
    $pdo->exec('INSERT INTO order_address SELECT * FROM customer_address');
    exit(0);
}
$pdo->exec('USE shopware_clone');
$run = static function (): void {
    passthru('php /opt/shopware-live-clone/anonymize-clone.php', $status);
    check($status === 0);
};
$run();
check($pdo->query('SELECT email FROM customer')->fetchColumn() === 'kunde-' . str_repeat('11',16) . '@example.invalid');
check((int) $pdo->query("SELECT COUNT(*) FROM order_customer WHERE email LIKE 'kunde-%@example.invalid' AND first_name='Test' AND last_name='Kunde'")->fetchColumn() === 2);
foreach (['customer_address', 'order_address'] as $table) {
    check((int) $pdo->query("SELECT COUNT(*) FROM $table WHERE street='Teststrasse 1' AND zipcode='12345' AND city='Teststadt' AND phone_number IS NULL AND additional_address_line1 IS NULL AND HEX(country_id)=REPEAT('33',16)")->fetchColumn() === 1);
}
check((int) $pdo->query('SELECT COUNT(*) FROM customer WHERE company IS NULL AND birthday IS NULL AND vat_ids IS NULL AND remote_address IS NULL')->fetchColumn() === 1);
check($pdo->query('SELECT email FROM live_fixture.customer')->fetchColumn() === 'private@example.org');
check($pdo->query('SELECT street FROM live_fixture.order_address')->fetchColumn() === 'Private Street 23');
$pdo->exec("UPDATE customer SET first_name='My test edit'");
$run();
check($pdo->query('SELECT first_name FROM customer')->fetchColumn() === 'My test edit');
// Upgrade of an existing ready clone must schedule a cache/search rebuild.
unlink('/var/lib/shopware-clone/anonymized-v1.json');
file_put_contents('/var/lib/shopware-clone/state.json', '{"phase":"ready","ready":true}');
$run();
check(json_decode(file_get_contents('/var/lib/shopware-clone/state.json'), true)['phase'] === 'configured');
echo "Anonymization: customer/order fields, guest/version rows, source isolation, once-only execution and upgrade rebuild: OK\n";
