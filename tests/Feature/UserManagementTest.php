<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\PmisEmployee;
use App\Models\User;
use App\Services\UserAccountService;
use Database\Seeders\BarangaySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BarangaySeeder::class);

        $this->adminUser = User::create([
            'id' => (string) Str::uuid(),
            'username' => 'admin_test',
            'first_name' => 'Admin',
            'last_name' => 'User',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $this->adminUser->assignRole('admin');
    }

    private function createUserHelper(array $attributes = []): User
    {
        return User::create(array_merge([
            'id' => (string) Str::uuid(),
            'username' => 'user_'.Str::random(6),
            'first_name' => 'Test',
            'last_name' => 'User',
            'password' => Hash::make('secret'),
            'is_active' => true,
        ], $attributes));
    }

    public function test_username_generator_formatting_and_normalization(): void
    {
        $service = app(UserAccountService::class);

        $this->assertEquals('j.delacruz', $service->generateUsername('Juan', 'Dela Cruz'));
        $this->assertEquals('m.santos', $service->generateUsername('Maria', 'Santos'));
        $this->assertEquals('j.reyes', $service->generateUsername('John Michael', 'Reyes'));
        $this->assertEquals('a.deleon', $service->generateUsername('Ana', 'De-Leon'));
        $this->assertEquals('j.oconnor', $service->generateUsername('Jose', "O'Connor"));
        $this->assertEquals('m.delapena', $service->generateUsername('Mark', 'Dela Peña'));
        $this->assertEquals('a.cruz', $service->generateUsername('Anne-Marie', 'Cruz'));
    }

    public function test_username_generator_collision_increments_suffix(): void
    {
        $service = app(UserAccountService::class);

        $this->createUserHelper(['username' => 'j.delacruz']);
        $this->assertEquals('j.delacruz2', $service->generateUsername('Juan', 'Dela Cruz'));

        $this->createUserHelper(['username' => 'j.delacruz2']);
        $this->assertEquals('j.delacruz3', $service->generateUsername('Juan', 'Dela Cruz'));
    }

    public function test_username_generator_collides_with_soft_deleted_users(): void
    {
        $service = app(UserAccountService::class);

        $user = $this->createUserHelper(['username' => 'j.delacruz']);
        $user->delete(); // Soft delete

        $this->assertEquals('j.delacruz2', $service->generateUsername('Juan', 'Dela Cruz'));
    }

    public function test_get_barangays_endpoint_returns_23_items(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/barangays');

        $response->assertStatus(200)
            ->assertJsonCount(23, 'data')
            ->assertJsonFragment(['name' => 'Apokon'])
            ->assertJsonFragment(['name' => 'Visayan Village']);
    }

    public function test_manual_user_creation_with_birthday_password_and_creation_source(): void
    {
        $barangay = Barangay::where('name', 'Apokon')->firstOrFail();
        $role = Role::firstOrFail();

        $payload = [
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'birthdate' => '1998-07-15',
            'barangay_id' => $barangay->id,
            'role' => $role->name,
            'email' => 'juan@example.com',
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/users', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('username', 'j.delacruz')
            ->assertJsonPath('first_name', 'Juan')
            ->assertJsonPath('middle_name', 'Santos')
            ->assertJsonPath('last_name', 'Dela Cruz')
            ->assertJsonPath('must_change_password', true)
            ->assertJsonPath('creation_source', 'MANUAL');

        $user = User::where('username', 'j.delacruz')->firstOrFail();
        $this->assertTrue(Hash::check('07151998', $user->password));
        $this->assertEquals('MANUAL', $user->creation_source);
        $this->assertEquals($barangay->id, $user->barangay_id);
    }

    public function test_manual_user_creation_without_email_is_accepted(): void
    {
        $barangay = Barangay::firstOrFail();
        $role = Role::firstOrFail();

        $payload = [
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthdate' => '2001-12-03',
            'barangay_id' => $barangay->id,
            'role' => $role->name,
            'email' => null,
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/users', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('username', 'm.santos');

        $user = User::where('username', 'm.santos')->firstOrFail();
        $this->assertTrue(Hash::check('12032001', $user->password));
        $this->assertNull($user->email);
    }

    public function test_duplicate_non_null_email_is_rejected(): void
    {
        $this->createUserHelper(['email' => 'taken@example.com']);

        $barangay = Barangay::firstOrFail();
        $role = Role::firstOrFail();

        $payload = [
            'first_name' => 'Pedro',
            'last_name' => 'Penduko',
            'birthdate' => '1995-05-05',
            'barangay_id' => $barangay->id,
            'role' => $role->name,
            'email' => 'taken@example.com',
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/users', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_inactive_barangay_is_rejected(): void
    {
        $inactiveBarangay = Barangay::create([
            'name' => 'Inactive Barangay',
            'city' => 'Tagum City',
            'is_active' => false,
        ]);
        $role = Role::firstOrFail();

        $payload = [
            'first_name' => 'Test',
            'last_name' => 'User',
            'birthdate' => '2000-01-01',
            'barangay_id' => $inactiveBarangay->id,
            'role' => $role->name,
        ];

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/users', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['barangay_id']);
    }

    public function test_unauthorized_user_cannot_preview_or_create_users(): void
    {
        $regularUser = $this->createUserHelper(['username' => 'regular_user']);
        $regularUser->syncRoles([]);

        $this->actingAs($regularUser)
            ->postJson('/api/users/username-preview', ['firstName' => 'Juan', 'lastName' => 'Dela Cruz'])
            ->assertStatus(403);

        $this->actingAs($regularUser)
            ->postJson('/api/users', ['first_name' => 'Juan', 'last_name' => 'Dela Cruz'])
            ->assertStatus(403);
    }

    public function test_username_preview_endpoint(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/users/username-preview', [
                'firstName' => 'Juan',
                'lastName' => 'Dela Cruz',
            ]);

        $response->assertStatus(200)
            ->assertJson(['username' => 'j.delacruz']);
    }

    public function test_pmis_user_creation_uses_universal_generator_format(): void
    {
        $pmisEmp = PmisEmployee::create([
            'id' => (string) Str::uuid(),
            'pmis_no' => 'PMIS-9999',
            'first_name' => 'Rex Jasper',
            'last_name' => 'Aala',
            'birth_date' => '1990-01-01',
            'designation' => 'Officer',
            'office' => 'City Mayor Office',
            'employment_status' => 'Permanent',
        ]);

        $role = Role::firstOrFail();

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/users', [
                'pmis_employee_id' => $pmisEmp->id,
                'role' => $role->name,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('username', 'r.aala')
            ->assertJsonPath('creation_source', 'PMIS');

        $user = User::where('username', 'r.aala')->firstOrFail();
        $this->assertEquals('PMIS', $user->creation_source);
        $this->assertTrue(Hash::check('01011990', $user->password));
    }

    public function test_login_and_forced_password_change_flow_for_manual_users(): void
    {
        $barangay = Barangay::firstOrFail();
        $role = Role::firstOrFail();

        // 1. Admin creates user
        $createRes = $this->actingAs($this->adminUser)
            ->postJson('/api/users', [
                'first_name' => 'Mario',
                'last_name' => 'Bros',
                'birthdate' => '1985-09-13',
                'barangay_id' => $barangay->id,
                'role' => $role->name,
            ]);

        $createRes->assertStatus(201);
        $username = $createRes->json('username'); // m.bros

        // 2. User logs in with initial birthday password (09131985)
        $loginRes = $this->postJson('/api/login', [
            'username' => $username,
            'password' => '09131985',
        ]);

        $loginRes->assertStatus(200)
            ->assertJsonPath('must_change_password', true);

        $user = User::where('username', $username)->firstOrFail();

        // 3. User attempts to change password using same birthday password -> REJECTED
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $changeFailRes = $this->postJson('/api/me/change-password', [
            'current_password' => '09131985',
            'new_password' => '09131985',
        ]);

        $changeFailRes->assertStatus(422)
            ->assertJsonValidationErrors(['new_password']);

        // 4. User changes password to new strong password -> SUCCESS
        $changeSuccessRes = $this->postJson('/api/me/change-password', [
            'current_password' => '09131985',
            'new_password' => 'NewSecurePass123!',
        ]);

        $changeSuccessRes->assertStatus(200)
            ->assertJsonPath('must_change_password', false);

        $user->refresh();
        $this->assertFalse((bool) $user->must_change_password);
        $this->assertTrue(Hash::check('NewSecurePass123!', $user->password));
    }

    public function test_activity_logs_record_creation_source_without_exposing_birthday_password(): void
    {
        $barangay = Barangay::firstOrFail();
        $role = Role::firstOrFail();

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/users', [
                'first_name' => 'Luigi',
                'last_name' => 'Bros',
                'birthdate' => '1985-09-13',
                'barangay_id' => $barangay->id,
                'role' => $role->name,
            ]);

        $response->assertStatus(201);
        $userId = $response->json('id');

        $user = User::findOrFail($userId);
        $this->assertEquals('MANUAL', $user->creation_source);

        $activity = \App\Models\ActivityEvent::where('resource_id', $userId)->firstOrFail();
        $this->assertEquals('account.created', $activity->activity_type);

        // Ensure password ('09131985') is NEVER recorded in activity description or event
        $this->assertStringNotContainsString('09131985', json_encode($activity->toArray()));
    }

    public function test_user_list_api_returns_barangay_for_manual_users_and_employment_status_for_pmis_users(): void
    {
        $barangay = Barangay::where('name', 'Apokon')->first() ?? Barangay::firstOrFail();
        $role = Role::firstOrFail();

        // 1. Create Manual User with Barangay Apokon
        $manualUserRes = $this->actingAs($this->adminUser)
            ->postJson('/api/users', [
                'first_name' => 'KJ',
                'last_name' => 'JK',
                'birthdate' => '1992-04-10',
                'barangay_id' => $barangay->id,
                'role' => $role->name,
            ]);

        $manualUserRes->assertStatus(201)
            ->assertJsonPath('creation_source', 'MANUAL')
            ->assertJsonPath('barangay.id', $barangay->id)
            ->assertJsonPath('barangay.name', $barangay->name);

        // 2. Create PMIS User
        $pmisEmp = PmisEmployee::create([
            'id' => (string) Str::uuid(),
            'pmis_no' => 'PMIS-8888',
            'first_name' => 'RJ',
            'last_name' => 'Aala',
            'birth_date' => '1990-05-15',
            'designation' => 'Officer',
            'office' => 'CHRMO',
            'employment_status' => 'Permanent',
        ]);

        $pmisUserRes = $this->actingAs($this->adminUser)
            ->postJson('/api/users', [
                'pmis_employee_id' => $pmisEmp->id,
                'role' => $role->name,
            ]);

        $pmisUserRes->assertStatus(201)
            ->assertJsonPath('creation_source', 'PMIS')
            ->assertJsonPath('employment_status', 'Permanent');

        // 3. Fetch User List Endpoint and Assert Both Regression Safeguards
        $listRes = $this->actingAs($this->adminUser)
            ->getJson('/api/users');

        $listRes->assertStatus(200);
        $manualRow = collect($listRes->json('data'))->firstWhere('username', 'k.jk');
        $pmisRow = collect($listRes->json('data'))->firstWhere('username', 'r.aala');

        // Manual User Regression Assertion: creation_source = MANUAL, barangay.name = Apokon
        $this->assertNotNull($manualRow);
        $this->assertEquals('MANUAL', $manualRow['creation_source']);
        $this->assertEquals($barangay->name, $manualRow['barangay']['name'] ?? null);

        // PMIS User Regression Assertion: creation_source = PMIS, employment_status = Permanent
        $this->assertNotNull($pmisRow);
        $this->assertEquals('PMIS', $pmisRow['creation_source']);
        $this->assertEquals('Permanent', $pmisRow['employment_status'] ?? null);
    }
}
