<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityEvent extends Model
{
    protected $fillable = [
        'actor_user_id',
        'activity_type',
        'module',
        'resource_type',
        'resource_id',
        'description',
        'outcome',
        'office_id',
        'division_id',
        'ip_address',
        'user_agent',
        'request_id',
        'session_id',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }
}
