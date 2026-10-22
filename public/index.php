<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\SimulatorController;
use App\Http\Router;

$router = new Router();
$sim = new SimulatorController();
$api = new ApiController();
$admin = new AdminController();

$router->get('/', fn () => $sim->index());
$router->get('/favicon.ico', fn () => \App\Http\Response::redirect('/assets/img/favicon.svg'));
$router->get('/api/underlyings', fn () => $api->underlyings());
$router->get('/api/snapshot', fn () => $api->snapshot());
$router->get('/api/chain', fn () => $api->chain());
$router->get('/api/quotes', fn () => $api->quotes());
$router->get('/api/series', fn () => $api->series());
$router->get('/api/spot-series', fn () => $api->spotSeries());
$router->get('/api/step', fn () => $api->step());

$router->get('/admin/login', fn () => $admin->loginForm());
$router->post('/admin/login', fn () => $admin->login());
$router->post('/admin/logout', fn () => $admin->logout());
$router->get('/admin', fn () => $admin->dashboard());
$router->get('/admin/underlyings', fn () => $admin->underlyings());
$router->post('/admin/underlyings', fn () => $admin->saveUnderlying());
$router->post('/admin/underlyings/{id}/delete', fn ($p) => $admin->deleteUnderlying((int) $p['id']));
$router->get('/admin/settings', fn () => $admin->settings());
$router->post('/admin/settings', fn () => $admin->saveSettings());
$router->get('/admin/jobs', fn () => $admin->jobs());
$router->post('/admin/jobs/plan', fn () => $admin->planJobs());
$router->post('/admin/jobs/refresh', fn () => $admin->refreshJobs());
$router->post('/admin/jobs/plan-dhan', fn () => $admin->planDhanJobs());
$router->post('/admin/jobs/run', fn () => $admin->runJobs());
$router->post('/admin/jobs/retry-failed', fn () => $admin->retryFailed());
$router->post('/admin/jobs/clear', fn () => $admin->clearJobs());
$router->get('/admin/holidays', fn () => $admin->holidays());
$router->post('/admin/holidays', fn () => $admin->saveHoliday());
$router->post('/admin/holidays/{day}/delete', fn ($p) => $admin->deleteHoliday($p['day']));
$router->get('/admin/account', fn () => $admin->account());
$router->post('/admin/account', fn () => $admin->saveAccount());

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
