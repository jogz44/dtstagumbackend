<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $subject_user_id
 * @property int|null $actor_user_id
 * @property string $actor_type
 * @property string $event_type
 * @property string $summary
 * @property array|null $changes
 * @property string|null $office_id
 * @property string|null $division_id
 * @property string|null $request_id
 * @property string|null $source_ip
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon|null $occurred_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $subjectUser
 * @property-read \App\Models\User|null $actorUser
 */
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
        return $this->belongsTo(User::class, 'subject_user_id')->withTrashed();
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }
}
