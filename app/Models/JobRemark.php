<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobRemark extends Model
{
    protected $fillable = [
        'emergency_job_id',
        'user_id',
        'body',
    ];

    public function emergencyJob(): BelongsTo
    {
        return $this->belongsTo(EmergencyJob::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
