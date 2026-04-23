<?php
$host    = getenv('PALVIN_DB_HOST')    ?: '127.0.0.1';
$port    = getenv('PALVIN_DB_PORT')    ?: '';
$db      = getenv('PALVIN_DB_NAME')    ?: 'palvin_premium';
$user    = getenv('PALVIN_DB_USER')    ?: 'palvinpavilion';
$pass    = (getenv('PALVIN_DB_PASS') !== false) ? getenv('PALVIN_DB_PASS') : 'h2SsD1TkzfYvG3n';
$charset = getenv('PALVIN_DB_CHARSET') ?: 'utf8mb4';

$dsn = 'mysql:host=' . $host . ';'
     . ($port !== '' ? 'port=' . $port . ';' : '')
     . 'dbname=' . $db . ';charset=' . $charset;

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $mysqlTimezone = is_array($config ?? null)
        ? ($config['mysql_timezone_offset'] ?? '+07:00')
        : '+07:00';
    if (is_string($mysqlTimezone) && $mysqlTimezone !== '') {
        $pdo->exec('SET time_zone = ' . $pdo->quote($mysqlTimezone));
    }
} catch (Throwable $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}
