<?php

namespace App\Policies;

use App\Models\User;

class DocumentPolicy
{
    public function viewConfidential(User $user, mixed $document = null): bool
    {
        return $user->can('documents.view_confidential');
    }
}
