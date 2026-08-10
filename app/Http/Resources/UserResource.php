<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'employee_id' => $this->employee_id,
            'birthdate' => $this->birthdate?->toDateString(),
            'position' => $this->position,
            'avatar_url' => $this->avatar_url,
            'is_active' => $this->is_active,
            'must_change_password' => $this->must_change_password,
            'office' => $this->whenLoaded('office'),
            'division' => $this->whenLoaded('division'),
            'roles' => $this->getRoleNames()->values(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
            'permission_overrides' => $this->whenLoaded('permissionOverrides'),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
