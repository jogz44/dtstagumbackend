<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionSoftDeleteSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_and_permissions_can_be_queried_without_soft_delete_column_errors(): void
    {
        $this->assertIsArray(Role::query()->pluck('name')->toArray());
        $this->assertIsArray(Permission::query()->pluck('name')->toArray());
    }
}
