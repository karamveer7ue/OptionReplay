<?php
declare(strict_types=1);

use App\Core\Env;

require __DIR__ . '/../vendor/autoload.php';

Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Asia/Kolkata'));

if (Env::get('APP_ENV', 'production') === 'local') {
    error_reporting(E_ALL);
    ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');
}

define('APP_ROOT', dirname(__DIR__));
