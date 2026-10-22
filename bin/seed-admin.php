#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Database;

$email = $argv[1] ?? null;
if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/seed-admin.php <email> [password]\n");
    exit(1);
}
$password = $argv[2] ?? bin2hex(random_bytes(8));
$hash = password_hash($password, PASSWORD_ARGON2ID);

$exists = Database::one('SELECT id FROM admins WHERE email = ?', [$email]);
if ($exists) {
    Database::run('UPDATE admins SET password_hash = ? WHERE id = ?', [$hash, $exists['id']]);
    echo "Password reset for {$email}\n";
} else {
    Database::run('INSERT INTO admins (email, password_hash) VALUES (?, ?)', [$email, $hash]);
    echo "Admin created: {$email}\n";
}
echo "Password: {$password}\n";
echo "Change it after first login (Admin > Account).\n";
