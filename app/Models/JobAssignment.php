<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobAssignment extends Model
{
    protected $fillable = [
        'emergency_job_id',
        'fire_station_id',
        'vehicle_id',
        'team_id',
        'team_leader_user_id',
        'assigned_by',
        'assigned_at',
        'released_at',
        'status',
        'remarks',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }

    public function emergencyJob(): BelongsTo
    {
        return $this->belongsTo(EmergencyJob::class);
    }

    public function fireStation(): BelongsTo
    {
        return $this->belongsTo(FireStation::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function teamLeader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_leader_user_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(JobStatusHistory::class);
    }

    public function gpsLogs(): HasMany
    {
        return $this->hasMany(VehicleGpsLog::class);
    }
}
