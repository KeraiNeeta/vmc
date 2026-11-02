<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: static fn (string $value): string => Str::lower(trim($value)),
        );
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot('assigned_by')
            ->withTimestamps();
    }

    public function fireStations(): BelongsToMany
    {
        return $this->belongsToMany(FireStation::class, 'user_fire_stations')
            ->withPivot('assigned_by')
            ->withTimestamps();
    }

    public function managedFireStations(): HasMany
    {
        return $this->hasMany(FireStation::class, 'admin_user_id');
    }

    public function ledTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'leader_user_id');
    }

    public function jobAssignments(): HasMany
    {
        return $this->hasMany(JobAssignment::class, 'assigned_by');
    }

    public function hasRole(string $code): bool
    {
        return $this->roles()->where('code', $code)->exists();
    }

    public function hasPermission(string $code): bool
    {
        return $this->hasRole('SUPER_ADMIN')
            || $this->roles()
                ->whereHas('permissions', fn ($query) => $query->where('permissions.code', $code))
                ->exists();
    }

    public function canAccessFireStation(FireStation|int $station): bool
    {
        if ($this->hasRole('SUPER_ADMIN')) {
            return true;
        }

        $stationId = $station instanceof FireStation ? $station->getKey() : $station;

        return $this->fireStations()->whereKey($stationId)->exists()
            || FireStation::query()
                ->whereKey($stationId)
                ->where('admin_user_id', $this->getKey())
                ->exists();
    }
}
