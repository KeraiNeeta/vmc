<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ward extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function fireStations(): BelongsToMany
    {
        return $this->belongsToMany(FireStation::class, 'station_wards')
            ->withTimestamps();
    }

    public function emergencyJobs(): HasMany
    {
        return $this->hasMany(EmergencyJob::class);
    }
}
