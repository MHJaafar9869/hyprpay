<?php

declare(strict_types=1);

use Hyprpay\Payments\Infrastructure\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Monitoring dashboard routes
|--------------------------------------------------------------------------
|
| Loaded by GatewayServiceProvider inside a group that applies the configured
| URL prefix, the AuthorizeDashboard gate, and the host's middleware stack, so
| these are declared relative to that group (path prefix and name prefix
| "hyprpay.dashboard.").
|
*/

Route::get('/', [DashboardController::class, 'index'])->name('index');
Route::get('/activity', [DashboardController::class, 'activity'])->name('activity');
Route::post('/lookup', [DashboardController::class, 'lookup'])->name('lookup');
