<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property string $id
 * @property string $username
 * @property string $first_name
 * @property string $last_name
 * @property string|null $office_id
 * @property string|null $division_id
 * @property string|null $position
 * @property string|null $employee_id
 * @property bool $is_active
 * @property-read string $full_name
 */
class User extends Authenticatable
{
    use HasApiTokens, HasRoles, HasUuids, Notifiable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'username',
        'password',
        'must_change_password',
        'first_name',
        'last_name',
        'office_id',
        'division_id',
        'employee_id',
        'birthdate',
        'position',
        'employment_status',
        'avatar_url',
        'is_active',
        'email',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'birthdate' => 'date',
        'is_active' => 'boolean',
        'must_change_password' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function createdDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'created_by');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(UserPermissionOverride::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
