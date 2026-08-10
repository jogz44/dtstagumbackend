<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountHistoryEvent extends Model
{
    protected $fillable = [
        'subject_user_id',
        'actor_user_id',
        'actor_type',
        'event_type',
        'summary',
        'changes',
        'office_id',
        'division_id',
        'request_id',
        'source_ip',
        'user_agent',
        'occurred_at',
    ];

    protected $casts = [
        'changes' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
