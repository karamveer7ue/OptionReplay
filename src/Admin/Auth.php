<?php
declare(strict_types=1);

namespace App\Admin;

use App\Core\Database;

final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => (($_SERVER['HTTPS'] ?? '') !== '')]);
            session_name('optsim_admin');
            session_start();
        }
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        self::start();
        $id = $_SESSION['admin_id'] ?? null;
        if (!$id) {
            return null;
        }
        return Database::one('SELECT id, email, last_login_at FROM admins WHERE id = ?', [$id]);
    }

    public static function attempt(string $email, string $password): bool
    {
        self::start();
        $row = Database::one('SELECT * FROM admins WHERE email = ?', [$email]);
        if ($row === null || !password_verify($password, $row['password_hash'])) {
            usleep(300000);
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $row['id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        Database::run('UPDATE admins SET last_login_at = NOW() WHERE id = ?', [$row['id']]);
        return true;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }

    public static function csrf(): string
    {
        self::start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(): void
    {
        self::start();
        $token = $_POST['_csrf'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
            throw new \App\Http\HttpException('Invalid CSRF token', 403);
        }
    }
}
