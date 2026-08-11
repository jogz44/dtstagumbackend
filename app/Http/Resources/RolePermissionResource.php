<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RolePermissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $permissions = $this->permissions->pluck('name')->toArray();
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'permissions' => [
                'can_receive_documents' => in_array('can_receive_documents', $permissions),
                'can_transfer_documents' => in_array('can_transfer_documents', $permissions),
                'can_view_confidential' => in_array('can_view_confidential', $permissions),
                'can_edit_document' => in_array('can_edit_document', $permissions),
                'can_share_document' => in_array('can_share_document', $permissions),
            ],
        ];
    }
}
