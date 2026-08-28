<?php

namespace App\Http\Resources;

use App\Support\RoleNames;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserAccessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = $this->roles->first();

        return [
            'id' => $this->id,
            'username' => $this->username,
            'employee_id' => $this->employee_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'office' => $this->office?->name ?? $this->pmisEmployee?->office,
            'division' => $this->division?->name ?? $this->pmisEmployee?->division,
            'role_id' => $role?->id,
            'role' => $role?->name,
            'role_label' => $role ? RoleNames::label($role->name) : null,
            'is_active' => (bool) $this->is_active,
            'account_status' => $this->account_status,
            'permission_overrides' => $this->whenLoaded('permissionOverrides', function () {
                return $this->permissionOverrides->map(fn ($override) => [
                    'permission_name' => $override->permission_name,
                    'effect' => $override->effect,
                ])->values();
            }, []),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
