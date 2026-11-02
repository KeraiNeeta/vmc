<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobExternalReference extends Model
{
    protected $fillable = [
        'emergency_job_id',
        'reference_type',
        'reference_number',
        'details',
        'transferred_at',
        'confirmed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transferred_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    public function emergencyJob(): BelongsTo
    {
        return $this->belongsTo(EmergencyJob::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
