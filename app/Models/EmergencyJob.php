<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmergencyJob extends Model
{
    use SoftDeletes;

    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_ASSIGNED = 'ASSIGNED';

    public const STATUS_EN_ROUTE = 'EN_ROUTE';

    public const STATUS_ARRIVED = 'ARRIVED';

    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_DUPLICATE = 'DUPLICATE';

    public const STATUS_TRANSFERRED_TO_112 = 'TRANSFERRED_TO_112';

    protected $fillable = [
        'job_id',
        'caller_name',
        'caller_phone',
        'call_source',
        'incident_type',
        'priority',
        'occurred_at',
        'ward_id',
        'society_name',
        'incident_address',
        'latitude',
        'longitude',
        'remarks',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function callLogs(): HasMany
    {
        return $this->hasMany(JobCallLog::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(JobAssignment::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(JobStatusHistory::class)->orderBy('changed_at');
    }

    public function remarksHistory(): HasMany
    {
        return $this->hasMany(JobRemark::class);
    }

    public function externalReferences(): HasMany
    {
        return $this->hasMany(JobExternalReference::class);
    }
}
