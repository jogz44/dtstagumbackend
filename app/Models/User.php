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
 *
 * @property string $id
 * @property string $username
 * @property string|null $password
 * @property bool $must_change_password
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string $full_name
 * @property string|null $office_id
 * @property string|null $division_id
 * @property string|null $employee_id
 * @property string|null $pmis_employee_id
 * @property \Illuminate\Support\Carbon|null $birthdate
 * @property string|null $position
 * @property string|null $employment_status
 * @property string|null $avatar_url
 * @property bool $is_active
 * @property string|null $email
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class User extends Authenticatable
{
    use HasApiTokens, HasRoles, HasUuids, Notifiable, SoftDeletes;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $appends = [
        'full_name',
        'account_status',
    ];

    protected $fillable = [
        'username',
        'password',
        'must_change_password',
        'first_name',
        'middle_name',
        'last_name',
        'office_id',
        'division_id',
        'barangay_id',
        'employee_id',
        'pmis_employee_id',
        'birthdate',
        'position',
        'employment_status',
        'avatar_url',
        'is_active',
        'email',
        'creation_source',
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

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

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

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getAccountStatusAttribute(): string
    {
        if ($this->deleted_at !== null) {
            return 'deactivated';
        }

        if (! $this->is_active) {
            return 'inactive';
        }

        return 'active';
    }
}
