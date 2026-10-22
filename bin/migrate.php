#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Database;
use App\Core\Env;

$dbName = Env::require('DB_NAME');
$server = Database::serverPdo();
$server->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "Database `{$dbName}` ready\n";

$pdo = Database::pdo();
$pdo->exec('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) NOT NULL PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
$applied = array_column(Database::all('SELECT name FROM migrations'), 'name');

$files = glob(APP_ROOT . '/database/migrations/*.sql') ?: [];
sort($files);
$count = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException("Cannot read {$file}");
    }
    // Strip full-line comments, then split on ; at end of statement.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $pdo->exec($statement);
    }
    Database::run('INSERT INTO migrations (name) VALUES (?)', [$name]);
    echo "Applied {$name}\n";
    $count++;
}
echo $count === 0 ? "Nothing to migrate\n" : "Done: {$count} migration(s)\n";
