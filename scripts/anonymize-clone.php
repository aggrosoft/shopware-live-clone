<?php
declare(strict_types=1);

function anonymizeCloneCustomers(PDO $pdo): array
{
    $counts = [];
    $pdo->beginTransaction();
    try {
        foreach (['customer', 'customer_address', 'order_customer', 'order_address'] as $table) {
            $columns = array_column($pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC), 'Field');
            $values = ['first_name' => "'Test'", 'last_name' => "'Kunde'"];
            if ($table === 'customer' || $table === 'order_customer') {
                $id = $table === 'customer' ? 'id' : 'COALESCE(customer_id, id)';
                $values['email'] = "CONCAT('kunde-', LOWER(HEX($id)), '@example.invalid')";
            } else {
                $values += ['street' => "'Teststrasse 1'", 'zipcode' => "'12345'", 'city' => "'Teststadt'"];
            }
            foreach (['title', 'company', 'department', 'phone_number', 'additional_address_line1',
                'additional_address_line2', 'vat_id', 'vat_ids', 'birthday', 'remote_address'] as $column) {
                if (in_array($column, $columns, true)) { $values[$column] = 'NULL'; }
            }
            $assignments = [];
            foreach ($values as $column => $value) { $assignments[] = '`' . $column . '`=' . $value; }
            $counts[$table] = $pdo->exec('UPDATE `' . $table . '` SET ' . implode(', ', $assignments));
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
    return $counts;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $data = '/var/lib/shopware-clone';
    umask(0077);
    try {
        if (is_file($data . '/anonymized-v1.json')) {
            echo "Customer anonymization already applied; keeping subsequent test changes.\n";
            exit(0);
        }
        $state = json_decode(file_get_contents($data . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!in_array($state['phase'] ?? '', ['imported', 'configured', 'ready'], true)) {
            throw new RuntimeException('Expected a completed local import.');
        }
        // Existing clones also rebuild caches and search indexes before services start.
        if ($state['phase'] === 'ready') {
            $state['phase'] = 'configured';
            $state['ready'] = false;
            if (file_put_contents($data . '/state.json', json_encode($state, JSON_THROW_ON_ERROR)) === false) {
                throw new RuntimeException('Cannot schedule local cache/index rebuild.');
            }
        }
        // Fixed local connection. Never read or use the source DATABASE_URL.
        $pdo = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=shopware_clone;charset=utf8mb4', 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $counts = anonymizeCloneCustomers($pdo);
        if (file_put_contents($data . '/anonymized-v1.json', json_encode(['version' => 1, 'updated_rows' => $counts], JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Cannot save anonymization status.');
        }
        foreach ($counts as $table => $count) { echo "Anonymized $table: $count rows.\n"; }
    } catch (Throwable $error) {
        file_put_contents($data . '/anonymize-error.log', (string) $error);
        fwrite(STDERR, "Customer anonymization failed. Details: /var/lib/shopware-clone/anonymize-error.log\n");
        exit(1);
    }
}
