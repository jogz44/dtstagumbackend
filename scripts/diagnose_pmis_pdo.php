<?php

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

$host = $_ENV['DB_PMIS_HOST'] ?? '10.0.1.35';
$port = $_ENV['DB_PMIS_PORT'] ?? '1433';
$database = $_ENV['DB_PMIS_DATABASE'] ?? 'pmis2003';
$username = $_ENV['DB_PMIS_USERNAME'] ?? 'hris';
$password = $_ENV['DB_PMIS_PASSWORD'] ?? '';

if ($password === '') {
    fwrite(STDERR, "DB_PMIS_PASSWORD is empty. Aborting without connecting.\n");
    exit(1);
}

$baseDsn = "sqlsrv:Server={$host},{$port};Database={$database}";

$tests = [
    'default/no encryption options' => '',
    'Encrypt=no;TrustServerCertificate=yes' => ';Encrypt=no;TrustServerCertificate=yes',
    'Encrypt=yes;TrustServerCertificate=yes' => ';Encrypt=yes;TrustServerCertificate=yes',
    'Encrypt=no' => ';Encrypt=no',
    'Encrypt=yes' => ';Encrypt=yes',
    'TrustServerCertificate=yes' => ';TrustServerCertificate=yes',
];

echo "PMIS PDO_SQLSRV diagnostic\n";
echo "Target: {$host}:{$port}\n";
echo "Database: {$database}\n";
echo "Username: {$username}\n";
echo "Password configured: yes\n";
echo str_repeat('-', 60) . "\n";

foreach ($tests as $label => $suffix) {
    $dsn = $baseDsn . $suffix;
    $shownOptions = $suffix === '' ? '(none)' : ltrim($suffix, ';');

    echo "Test: {$label}\n";
    echo "Options: {$shownOptions}\n";

    try {
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $row = $pdo->query('SELECT GETDATE() AS DatabaseServerTime, DB_NAME() AS DatabaseName')->fetch(PDO::FETCH_ASSOC);
        $dbName = $row['DatabaseName'] ?? $row['databasename'] ?? 'unknown';

        echo "Result: OK; connected database={$dbName}\n";
    } catch (Throwable $e) {
        echo 'Result: FAIL; ' . $e->getMessage() . "\n";
    }

    echo str_repeat('-', 60) . "\n";
}
