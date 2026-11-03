<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FleetVehicleResource;
use App\Http\Resources\JobAssignmentResource;
use App\Models\JobAssignment;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class FleetController extends Controller
{
    public function assignments(Request $request): JsonResponse
    {
        $driver = $request->user();
        $this->ensureDriver($driver);
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'between:1,50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $records = $this->activeAssignments($driver)
            ->with([
                'emergencyJob.ward:id,name,code',
                'fireStation:id,name,code',
                'vehicle:id,vehicle_number,vehicle_type,status,latitude,longitude',
                'team:id,name',
                'teamLeader:id,name',
            ])
            ->orderByDesc('assigned_at')
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString();

        return response()->json([
            'data' => JobAssignmentResource::collection($records->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    public function vehicles(Request $request): JsonResponse
    {
        $driver = $request->user();
        $this->ensureDriver($driver);
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'between:1,50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $records = Vehicle::query()
            ->whereHas('assignments', fn (Builder $assignments) => $this->scopeToDriver($assignments, $driver)->whereNull('released_at'))
            ->with([
                'fireStation:id,name,code',
                'latestGpsLog',
                'assignments' => fn (Relation $assignments) => $this->scopeToDriver($assignments, $driver)
                    ->whereNull('released_at')
                    ->with(['emergencyJob:id,job_id,incident_type,priority,status', 'team:id,name']),
            ])
            ->orderBy('vehicle_number')
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString();

        return response()->json([
            'data' => FleetVehicleResource::collection($records->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
        ]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $this->ensureDriver($request->user());
        $validated = $request->validate([
            'unread_only' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $notifications = $request->user()->notifications()
            ->when(
                filter_var($validated['unread_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
                fn ($query) => $query->whereNull('read_at'),
            )
            ->latest('created_at')
            ->paginate((int) ($validated['per_page'] ?? 15))
            ->withQueryString();

        return response()->json([
            'data' => $notifications->getCollection()->map(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'type' => $notification->data['event'] ?? $notification->type,
                'data' => $notification->data,
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'unread' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    public function markNotificationRead(Request $request, string $notificationId): JsonResponse
    {
        $this->ensureDriver($request->user());
        $notification = $request->user()->notifications()->whereKey($notificationId)->firstOrFail();
        $notification->markAsRead();

        return response()->json([
            'data' => [
                'id' => $notification->id,
                'read_at' => $notification->fresh()->read_at?->toIso8601String(),
            ],
        ]);
    }

    private function activeAssignments(User $driver): Builder
    {
        return $this->scopeToDriver(JobAssignment::query(), $driver)->whereNull('released_at');
    }

    private function scopeToDriver(Builder|Relation $assignments, User $driver): Builder|Relation
    {
        return $assignments->where(function (Builder $query) use ($driver): void {
            $query->where('team_leader_user_id', $driver->id)
                ->orWhereHas('team.members', function (Builder $members) use ($driver): void {
                    $members->where('users.id', $driver->id)
                        ->whereNull('team_members.left_at');
                });
        });
    }

    private function ensureDriver(User $user): void
    {
        abort_unless($user->hasRole('VEHICLE_DRIVER'), 403, 'Fleet endpoints are available to vehicle drivers only.');
    }
}
