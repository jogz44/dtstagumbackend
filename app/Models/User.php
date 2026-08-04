<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'office_id',
        'division_id',
        'username',
        'password',
        'first_name',
        'last_name',
        'employee_id',
        'birthdate',
        'position',
        'avatar_url',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
        ];
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

    public function hasPermissionTo($permission, $guardName = null): bool
    {
        $permissionName = is_string($permission) ? $permission : $permission->name;

        if ($this->permissionOverrides()->where('permission_name', $permissionName)->where('effect', 'deny')->exists()) {
            return false;
        }

        if ($this->permissionOverrides()->where('permission_name', $permissionName)->where('effect', 'allow')->exists()) {
            return true;
        }

        return parent::hasPermissionTo($permission, $guardName);
    }

    public function getAllPermissions()
    {
        $permissions = parent::getAllPermissions();

        $deniedPermissionNames = $this->permissionOverrides()
            ->where('effect', 'deny')
            ->pluck('permission_name')
            ->all();

        $allowedOverrides = $this->permissionOverrides()
            ->where('effect', 'allow')
            ->pluck('permission_name')
            ->all();

        return $permissions
            ->reject(fn($permission) => in_array($permission->name, $deniedPermissionNames, true))
            ->merge(
                Permission::query()
                    ->whereIn('name', $allowedOverrides)
                    ->get()
            )
            ->unique('name')
            ->values();
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }
}
