<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $actor_user_id
 * @property string $activity_type
 * @property string|null $module
 * @property string|null $resource_type
 * @property string|null $resource_id
 * @property string $description
 * @property string $outcome
 * @property string|null $office_id
 * @property string|null $division_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property string|null $session_id
 * @property \Illuminate\Support\Carbon|null $occurred_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $actorUser
 */
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
