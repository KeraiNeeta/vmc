<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CatalogMutationController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\IncidentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'store'])
        ->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'show']);
        Route::post('/auth/logout', [AuthController::class, 'destroy']);

        Route::get('/dashboard', DashboardController::class)
            ->middleware('permission:dashboard.view');

        Route::get('/jobs', [IncidentController::class, 'index'])
            ->middleware('permission:jobs.view');
        Route::get('/callers/search', [IncidentController::class, 'callerSearch'])
            ->middleware('permission:jobs.view');
        Route::post('/jobs', [IncidentController::class, 'store'])
            ->middleware('permission:jobs.create');
        Route::post('/jobs/{id}/duplicate', [IncidentController::class, 'closeDuplicate'])
            ->whereNumber('id')
            ->middleware('permission:jobs.close_duplicate');
        Route::post('/jobs/{id}/transfer-112', [IncidentController::class, 'transferTo112'])
            ->whereNumber('id')
            ->middleware('permission:jobs.transfer_112');

        Route::middleware('permission:stations.view')->get('/fire-stations', [CatalogController::class, 'fireStations']);
        Route::middleware('permission:wards.view')->get('/wards', [CatalogController::class, 'wards']);
        Route::middleware('permission:vehicles.view')->get('/vehicles', [CatalogController::class, 'vehicles']);
        Route::middleware('permission:teams.view')->get('/teams', [CatalogController::class, 'teams']);
        Route::middleware('permission:users.view')->get('/users', [CatalogController::class, 'users']);
        Route::middleware('permission:roles.view')->get('/roles', [CatalogController::class, 'roles']);
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        foreach ([
            'fire-stations' => 'stations',
            'wards' => 'wards',
            'vehicles' => 'vehicles',
            'teams' => 'teams',
        ] as $path => $kind) {
            Route::middleware('permission:'.$kind.'.manage')->group(function () use ($path, $kind): void {
                Route::post('/'.$path, [CatalogMutationController::class, 'store'])
                    ->defaults('kind', $kind);
                Route::put('/'.$path.'/{id}', [CatalogMutationController::class, 'update'])
                    ->whereNumber('id')
                    ->defaults('kind', $kind);
                Route::patch('/'.$path.'/{id}', [CatalogMutationController::class, 'update'])
                    ->whereNumber('id')
                    ->defaults('kind', $kind);
                Route::delete('/'.$path.'/{id}', [CatalogMutationController::class, 'destroy'])
                    ->whereNumber('id')
                    ->defaults('kind', $kind);
            });
        }
    });
});
