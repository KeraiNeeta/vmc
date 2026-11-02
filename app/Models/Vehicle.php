<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use SoftDeletes;

    public const STATUS_AVAILABLE = 'AVAILABLE';

    public const STATUS_DEPLOYED = 'DEPLOYED';

    public const STATUS_MAINTENANCE = 'MAINTENANCE';

    protected $fillable = [
        'fire_station_id',
        'vehicle_number',
        'vehicle_type',
        'registration_number',
        'gps_device_id',
        'latitude',
        'longitude',
        'status',
        'last_service_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'last_service_at' => 'immutable_datetime',
        ];
    }

    public function fireStation(): BelongsTo
    {
        return $this->belongsTo(FireStation::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(JobAssignment::class);
    }

    public function gpsLogs(): HasMany
    {
        return $this->hasMany(VehicleGpsLog::class);
    }
}
