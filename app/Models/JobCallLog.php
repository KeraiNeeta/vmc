<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobCallLog extends Model
{
    protected $fillable = [
        'emergency_job_id',
        'call_reference',
        'caller_name',
        'caller_phone',
        'call_source',
        'received_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime'];
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
