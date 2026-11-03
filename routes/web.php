<?php

use App\Http\Controllers\IncidentArchiveController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\OperationsReportController;
use App\Http\Controllers\OperationsReportExportController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\VehicleTrackingController;
use App\Http\Controllers\WebAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PageController::class, 'dashboard'])->name('home');
Route::get('/login', [WebAuthController::class, 'create'])->name('login');
Route::post('/login', [WebAuthController::class, 'store'])
    ->middleware('throttle:login')
    ->name('login.store');
Route::post('/logout', [WebAuthController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::get('/dashboard', [PageController::class, 'dashboard'])->name('dashboard');
Route::get('/dashboard/activity', [PageController::class, 'dashboardActivity'])
    ->middleware(['auth', 'permission:jobs.view'])
    ->name('dashboard.activity');

foreach (['stations', 'wards', 'vehicles', 'teams'] as $kind) {
    Route::middleware(['auth', 'permission:'.$kind.'.manage'])->group(function () use ($kind): void {
        Route::get('/'.$kind.'/create', [MasterDataController::class, 'create'])
            ->defaults('kind', $kind)
            ->name('app.'.$kind.'.create');
        Route::post('/'.$kind, [MasterDataController::class, 'store'])
            ->defaults('kind', $kind)
            ->name('app.'.$kind.'.store');
        Route::get('/'.$kind.'/{id}/edit', [MasterDataController::class, 'edit'])
            ->whereNumber('id')
            ->defaults('kind', $kind)
            ->name('app.'.$kind.'.edit');
        Route::put('/'.$kind.'/{id}', [MasterDataController::class, 'update'])
            ->whereNumber('id')
            ->defaults('kind', $kind)
            ->name('app.'.$kind.'.update');
        Route::delete('/'.$kind.'/{id}', [MasterDataController::class, 'destroy'])
            ->whereNumber('id')
            ->defaults('kind', $kind)
            ->name('app.'.$kind.'.destroy');
    });
}

Route::middleware(['auth', 'permission:users.view'])->group(function (): void {
    Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
});
Route::middleware(['auth', 'permission:users.manage'])->group(function (): void {
    Route::get('/users/create', [UserManagementController::class, 'create'])->name('users.create');
    Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
    Route::get('/users/{managedUser}/edit', [UserManagementController::class, 'edit'])->name('users.edit');
    Route::put('/users/{managedUser}', [UserManagementController::class, 'update'])->name('users.update');
});
Route::get('/users/{managedUser}', [UserManagementController::class, 'show'])
    ->middleware(['auth', 'permission:users.view'])
    ->name('users.show');

Route::middleware(['auth', 'permission:jobs.create'])->group(function (): void {
    Route::get('/jobs/new', [IncidentController::class, 'create'])->name('app.jobs.new');
    Route::post('/jobs', [IncidentController::class, 'store'])->name('app.jobs.store');
});
foreach (['pending', 'active', 'assigned', 'en-route', 'arrived', 'in-progress', 'completed', 'duplicates', 'transferred'] as $queue) {
    Route::get('/jobs/'.$queue, [IncidentController::class, 'queue'])
        ->defaults('state', $queue)
        ->middleware(['auth', 'permission:jobs.view'])
        ->name('app.jobs.'.$queue);
}
Route::get('/jobs/{job}', [IncidentController::class, 'show'])
    ->whereNumber('job')
    ->middleware(['auth', 'permission:jobs.view'])
    ->name('app.jobs.show');
Route::post('/jobs/{job}/archive', [IncidentArchiveController::class, 'archive'])
    ->whereNumber('job')
    ->middleware(['auth', 'permission:jobs.archive'])
    ->name('app.jobs.archive');
Route::post('/jobs/{job}/duplicate', [IncidentController::class, 'closeDuplicate'])
    ->whereNumber('job')
    ->middleware(['auth', 'permission:jobs.close_duplicate'])
    ->name('app.jobs.duplicate');
Route::post('/jobs/{job}/transfer-112', [IncidentController::class, 'transferTo112'])
    ->whereNumber('job')
    ->middleware(['auth', 'permission:jobs.transfer_112'])
    ->name('app.jobs.transfer-112');
Route::post('/jobs/{job}/assignments', [IncidentController::class, 'assignResources'])
    ->whereNumber('job')
    ->middleware(['auth', 'permission:jobs.assign'])
    ->name('app.jobs.assignments.store');
Route::patch('/jobs/{job}/assignments/{assignmentId}/status', [IncidentController::class, 'updateAssignmentStatus'])
    ->whereNumber('job')
    ->whereNumber('assignmentId')
    ->middleware(['auth', 'permission:jobs.update_status'])
    ->name('app.jobs.assignments.status');
Route::get('/tracking', [VehicleTrackingController::class, 'index'])
    ->middleware(['auth', 'permission:gps.view'])
    ->name('app.tracking');
Route::get('/reports', [OperationsReportController::class, 'index'])
    ->middleware(['auth', 'permission:reports.view'])
    ->name('app.reports');
Route::get('/reports/operations/export/{format}', [OperationsReportExportController::class, 'export'])
    ->whereIn('format', ['csv', 'xlsx', 'pdf'])
    ->middleware(['auth', 'permission:reports.export'])
    ->name('app.reports.export');
Route::get('/archive', [IncidentArchiveController::class, 'index'])
    ->middleware(['auth', 'permission:jobs.archive'])
    ->name('app.archive');
Route::post('/archive/{jobId}/restore', [IncidentArchiveController::class, 'restore'])
    ->whereNumber('jobId')
    ->middleware(['auth', 'permission:jobs.archive'])
    ->name('app.archive.restore');

foreach (['stations', 'wards', 'roles', 'vehicles', 'teams', 'jobs'] as $catalog) {
    Route::get('/'.$catalog, [PageController::class, 'catalog'])
        ->defaults('kind', $catalog)
        ->name('app.'.$catalog);
}
