<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * MSSMS ACCOUNT RETENTION POLICY
 *
 * User records must NEVER be physically deleted.
 *
 * Account removal is implemented only through:
 * - is_active = false
 * - SoftDeletes / deleted_at
 *
 * Never call forceDelete() for User records.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasRoles, HasUuids, Notifiable, SoftDeletes;

    public $incrementing = false;

    protected $appends = [
        'full_name',
    ];

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
        'pmis_employee_id',
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

    protected $attributes = [
        'must_change_password' => false,
    ];

    protected $casts = [
        'birthdate' => 'date',
        'is_active' => 'boolean',
        'must_change_password' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function pmisEmployee(): BelongsTo
    {
        return $this->belongsTo(PmisEmployee::class, 'pmis_employee_id');
    }

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
