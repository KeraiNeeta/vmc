<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FireStation;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    private const ROLE_LEVELS = [
        'SUPER_ADMIN' => 0,
        'CONTROL_ROOM_ADMIN' => 1,
        'FIRE_STATION_ADMIN' => 2,
        'DISPATCH_OPERATOR' => 3,
        'REPORT_VIEWER' => 3,
        'VEHICLE_DRIVER' => 4,
        'TEAM_MEMBER' => 5,
    ];

    public function index(Request $request): View
    {
        $actor = $request->user();
        $stations = $this->availableStations($actor);
        $stationIds = $stations->modelKeys();
        $query = User::query()->with([
            'roles',
            'fireStations' => fn (Builder|Relation $builder) => $builder->whereIn('fire_stations.id', $stationIds),
            'managedFireStations' => fn (Builder|Relation $builder) => $builder->whereIn('id', $stationIds),
        ]);
        $this->applyUserScope($query, $actor, $stationIds);

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(fn (Builder $users) => $users
                ->whereLike('name', '%'.$search.'%')
                ->orWhereLike('email', '%'.$search.'%')
                ->orWhereHas('roles', fn (Builder $roles) => $roles->whereLike('name', '%'.$search.'%')));
        }

        $users = $query->orderBy('name')->paginate(15)->withQueryString();
        $manageableUserIds = $users->getCollection()
            ->filter(fn (User $user) => $this->canManageUser($actor, $user))
            ->modelKeys();

        return view('users.index', [
            'users' => $users,
            'search' => $search,
            'manageableUserIds' => $manageableUserIds,
        ]);
    }

    public function create(Request $request): View
    {
        $actor = $request->user();

        return view('users.form', [
            'user' => null,
            'roles' => $this->assignableRoles($actor),
            'stations' => $this->availableStations($actor),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $actor = $request->user();
        $validated = $this->validateUser($request, $actor);

        $user = DB::transaction(function () use ($validated, $actor, $auditLogger): User {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'is_active' => $validated['is_active'],
            ]);
            $user->roles()->sync([Role::query()->where('code', $validated['role_code'])->value('id')]);
            $user->fireStations()->syncWithPivotValues(
                $validated['station_ids'],
                ['assigned_by' => $actor->id],
            );
            $auditLogger->record($actor, 'users.created', $user, [
                'role' => $validated['role_code'],
                'station_ids' => $validated['station_ids'],
            ]);

            return $user;
        });

        return redirect()->route('users.show', $user)->with('status', 'User account created.');
    }

    public function show(Request $request, User $managedUser): View
    {
        $actor = $request->user();
        abort_unless($this->canViewUser($actor, $managedUser), 404);
        $stationIds = $this->availableStations($actor)->modelKeys();
        $managedUser->load([
            'roles.permissions',
            'fireStations' => fn (Builder|Relation $builder) => $builder->whereIn('fire_stations.id', $stationIds),
            'managedFireStations' => fn (Builder|Relation $builder) => $builder->whereIn('id', $stationIds),
        ]);
        $logs = AuditLog::query()
            ->where('user_id', $managedUser->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['event', 'created_at']);

        return view('users.show', [
            'managedUser' => $managedUser,
            'stations' => $managedUser->fireStations
                ->merge($managedUser->managedFireStations)
                ->unique('id')
                ->values(),
            'logs' => $logs,
            'canEdit' => $managedUser->id !== $actor->id && $this->canManageUser($actor, $managedUser),
        ]);
    }

    public function edit(Request $request, User $managedUser): View
    {
        $this->authorizeManageTarget($request->user(), $managedUser);

        return view('users.form', [
            'user' => $managedUser->load(['roles', 'fireStations']),
            'roles' => $this->assignableRoles($request->user()),
            'stations' => $this->availableStations($request->user()),
        ]);
    }

    public function update(Request $request, User $managedUser, AuditLogger $auditLogger): RedirectResponse
    {
        $actor = $request->user();
        $this->authorizeManageTarget($actor, $managedUser);
        $validated = $this->validateUser($request, $actor, $managedUser);
        $this->assertExistingStationsInScope($actor, $managedUser);

        DB::transaction(function () use ($validated, $actor, $managedUser, $auditLogger): void {
            $attributes = [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'is_active' => $validated['is_active'],
            ];
            if ($validated['password'] !== null) {
                $attributes['password'] = $validated['password'];
            }

            $managedUser->update($attributes);
            $managedUser->roles()->sync([Role::query()->where('code', $validated['role_code'])->value('id')]);
            $managedUser->fireStations()->syncWithPivotValues(
                $validated['station_ids'],
                ['assigned_by' => $actor->id],
            );

            if (! $validated['is_active'] || $validated['password'] !== null) {
                $managedUser->tokens()->delete();
            }

            $auditLogger->record($actor, 'users.updated', $managedUser, [
                'role' => $validated['role_code'],
                'station_ids' => $validated['station_ids'],
                'is_active' => $validated['is_active'],
            ]);
        });

        return redirect()->route('users.show', $managedUser)->with('status', 'User account updated.');
    }

    private function validateUser(Request $request, User $actor, ?User $managedUser = null): array
    {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);
        $allowedStationIds = $this->availableStations($actor)->modelKeys();
        $allowedRoleCodes = $this->assignableRoles($actor)->pluck('code')->all();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($managedUser?->id),
            ],
            'role_code' => ['required', 'string', Rule::in($allowedRoleCodes)],
            'station_ids' => ['required', 'array', 'min:1'],
            'station_ids.*' => ['required', 'integer', 'distinct', Rule::in($allowedStationIds)],
            'password' => [$managedUser ? 'nullable' : 'required', 'string', 'min:14', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);

        $validated['email'] = mb_strtolower(trim($validated['email']));
        $validated['station_ids'] = array_map('intval', $validated['station_ids']);
        $validated['password'] ??= null;

        return $validated;
    }

    private function authorizeManageTarget(User $actor, User $target): void
    {
        abort_unless($this->canManageUser($actor, $target), 404);
        $this->assertExistingStationsInScope($actor, $target);
    }

    private function canViewUser(User $actor, User $target): bool
    {
        if ($this->canViewAllUsers($actor)) {
            return true;
        }

        $stationIds = $this->availableStations($actor)->modelKeys();

        return $target->fireStations()->whereIn('fire_stations.id', $stationIds)->exists()
            || $target->managedFireStations()->whereIn('id', $stationIds)->exists();
    }

    private function canManageUser(User $actor, User $target): bool
    {
        if ($target->id === $actor->id || ! $actor->hasPermission('users.manage')) {
            return false;
        }

        $actorLevel = $this->highestRoleLevel($actor);
        $targetRoles = $target->relationLoaded('roles')
            ? $target->roles->pluck('code')
            : $target->roles()->pluck('code');

        $hasLowerRole = $targetRoles->isEmpty()
            ? $actorLevel === 0
            : $targetRoles->every(fn (string $code) => (self::ROLE_LEVELS[$code] ?? 0) > $actorLevel);

        return $hasLowerRole && $this->userIsInScope($actor, $target);
    }

    private function assertExistingStationsInScope(User $actor, User $target): void
    {
        if ($this->canViewAllUsers($actor)) {
            return;
        }

        abort_unless($this->userIsInScope($actor, $target), 404);
    }

    private function userIsInScope(User $actor, User $target): bool
    {
        if ($this->canViewAllUsers($actor)) {
            return true;
        }

        $allowed = $this->availableStations($actor)->modelKeys();
        $assigned = $this->userStationIds($target);

        return $assigned->isNotEmpty() && $assigned->diff($allowed)->isEmpty();
    }

    private function userStationIds(User $user): Collection
    {
        $assigned = $user->relationLoaded('fireStations')
            ? $user->fireStations->modelKeys()
            : $user->fireStations()->pluck('fire_stations.id');
        $managed = $user->relationLoaded('managedFireStations')
            ? $user->managedFireStations->modelKeys()
            : $user->managedFireStations()->pluck('id');

        return collect($assigned)->merge($managed)->unique()->values();
    }

    private function applyUserScope(Builder $query, User $actor, array $stationIds): void
    {
        if ($this->canViewAllUsers($actor)) {
            return;
        }

        $query->where(fn (Builder $users) => $users
            ->whereHas(
                'fireStations',
                fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds),
            )
            ->orWhereHas(
                'managedFireStations',
                fn (Builder $stations) => $stations->whereIn('id', $stationIds),
            ));
    }

    private function availableStations(User $actor)
    {
        $query = FireStation::query()->orderBy('name');

        if (! $this->canViewAllUsers($actor)) {
            $query->whereKey($actor->fireStations()->pluck('fire_stations.id')
                ->merge($actor->managedFireStations()->pluck('id'))
                ->unique());
        }

        return $query->get(['id', 'code', 'name']);
    }

    private function assignableRoles(User $actor)
    {
        $level = $this->highestRoleLevel($actor);

        return Role::query()
            ->whereIn('code', collect(self::ROLE_LEVELS)
                ->filter(fn (int $roleLevel) => $roleLevel > $level)
                ->keys())
            ->orderByRaw('CASE code WHEN \'FIRE_STATION_ADMIN\' THEN 1 WHEN \'DISPATCH_OPERATOR\' THEN 2 WHEN \'REPORT_VIEWER\' THEN 3 WHEN \'VEHICLE_DRIVER\' THEN 4 WHEN \'TEAM_MEMBER\' THEN 5 ELSE 0 END')
            ->get();
    }

    private function highestRoleLevel(User $user): int
    {
        return $user->roles()->pluck('code')
            ->map(fn (string $code) => self::ROLE_LEVELS[$code] ?? PHP_INT_MAX)
            ->min() ?? PHP_INT_MAX;
    }

    private function canViewAllUsers(User $user): bool
    {
        return $user->hasRole('SUPER_ADMIN') || $user->hasRole('CONTROL_ROOM_ADMIN');
    }
}
