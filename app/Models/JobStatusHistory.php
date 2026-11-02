<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobStatusHistory extends Model
{
    protected $table = 'job_status_history';

    public $timestamps = false;

    protected $fillable = [
        'emergency_job_id',
        'job_assignment_id',
        'previous_status',
        'new_status',
        'changed_by',
        'changed_at',
        'remarks',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'immutable_datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function emergencyJob(): BelongsTo
    {
        return $this->belongsTo(EmergencyJob::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(JobAssignment::class, 'job_assignment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
