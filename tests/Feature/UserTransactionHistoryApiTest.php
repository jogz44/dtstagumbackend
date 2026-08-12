<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentHistory;
use App\Models\Division;
use App\Models\Office;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserTransactionHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_user_receives_registered_event(): void
    {
        $admin = $this->createSuperAdmin();
        $selectedUser = $this->createUser('registrar', 'Regina', 'Registrar');
        $document = $this->createDocumentFor($selectedUser, ['description' => 'Registered document']);
        $event = DocumentHistory::record($document, DocumentHistory::REGISTERED, $selectedUser, [
            'status_after' => 'outgoing',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/users/{$selectedUser->id}/transaction-history");

        $response
            ->assertOk()
            ->assertJsonPath('data.0.event_id', $event->id)
            ->assertJsonPath('data.0.document_id', $document->id)
            ->assertJsonPath('data.0.tracking_number', $document->tracking_number)
            ->assertJsonPath('data.0.particular', 'Registered document')
            ->assertJsonPath('data.0.action', DocumentHistory::REGISTERED)
            ->assertJsonPath('data.0.status', 'outgoing');
    }

    public function test_selected_user_receives_assigned_events_as_actor_and_assignee(): void
    {
        $admin = $this->createSuperAdmin();
        $selectedUser = $this->createUser('selected', 'Selena', 'Selected');
        $assignee = $this->createUser('assignee', 'Ariane', 'Assignee');

        $actorDocument = $this->createDocumentFor($selectedUser, ['description' => 'Actor assignment']);
        $actorEvent = DocumentHistory::record($actorDocument, DocumentHistory::ASSIGNED, $selectedUser, [
            'to_user_id' => $assignee->id,
            'status_before' => 'outgoing',
            'status_after' => 'outgoing',
        ]);

        $assigneeDocument = $this->createDocumentFor($admin, [
            'description' => 'Assignee assignment',
            'assigned_user' => $selectedUser->full_name,
        ]);
        $assigneeDocument->acknowledgements()->create([
            'user_id' => $selectedUser->id,
            'assigned_name' => $selectedUser->full_name,
        ]);
        $assigneeEvent = DocumentHistory::record($assigneeDocument, DocumentHistory::ASSIGNED, $admin, [
            'to_user_id' => $selectedUser->id,
            'status_before' => 'outgoing',
            'status_after' => 'outgoing',
        ]);

        Sanctum::actingAs($admin);

        $eventIds = collect($this->getJson("/api/users/{$selectedUser->id}/transaction-history")->json('data'))
            ->pluck('event_id')
            ->all();

        $this->assertContains($actorEvent->id, $eventIds);
        $this->assertContains($assigneeEvent->id, $eventIds);
    }

    public function test_unrelated_events_are_excluded_and_search_remains_scoped(): void
    {
        $admin = $this->createSuperAdmin();
        $selectedUser = $this->createUser('selected', 'Selena', 'Selected');
        $unrelated = $this->createUser('unrelated', 'Una', 'Related');

        $selectedDocument = $this->createDocumentFor($selectedUser, ['description' => 'Scoped payroll request']);
        $selectedEvent = DocumentHistory::record($selectedDocument, DocumentHistory::REGISTERED, $selectedUser);

        $unrelatedDocument = $this->createDocumentFor($unrelated, ['description' => 'Scoped unrelated request']);
        DocumentHistory::record($unrelatedDocument, DocumentHistory::REGISTERED, $unrelated);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/users/{$selectedUser->id}/transaction-history?search=Scoped");

        $response->assertOk();

        $this->assertSame([$selectedEvent->id], collect($response->json('data'))->pluck('event_id')->all());
    }

    public function test_results_are_newest_first_and_pagination_works(): void
    {
        $admin = $this->createSuperAdmin();
        $selectedUser = $this->createUser('selected', 'Selena', 'Selected');

        $oldEvent = DocumentHistory::record($this->createDocumentFor($selectedUser), DocumentHistory::REGISTERED, $selectedUser);
        $oldEvent->forceFill(['created_at' => now()->subDays(2)])->save();

        $newEvent = DocumentHistory::record($this->createDocumentFor($selectedUser), DocumentHistory::REGISTERED, $selectedUser);
        $newEvent->forceFill(['created_at' => now()])->save();

        for ($i = 0; $i < 24; $i++) {
            $event = DocumentHistory::record($this->createDocumentFor($selectedUser), DocumentHistory::REGISTERED, $selectedUser);
            $event->forceFill(['created_at' => now()->subDay()->subMinutes($i)])->save();
        }

        Sanctum::actingAs($admin);

        $firstPage = $this->getJson("/api/users/{$selectedUser->id}/transaction-history?page_size=25");

        $firstPage
            ->assertOk()
            ->assertJsonPath('data.0.event_id', $newEvent->id)
            ->assertJsonPath('pagination.per_page', 25)
            ->assertJsonPath('pagination.total', 26)
            ->assertJsonPath('pagination.last_page', 2);

        $secondPage = $this->getJson("/api/users/{$selectedUser->id}/transaction-history?page_size=25&page=2");

        $this->assertSame([$oldEvent->id], collect($secondPage->json('data'))->pluck('event_id')->all());
    }

    public function test_same_event_is_returned_once_when_user_has_multiple_involvement_roles(): void
    {
        $admin = $this->createSuperAdmin();
        $selectedUser = $this->createUser('selected', 'Selena', 'Selected');
        $document = $this->createDocumentFor($selectedUser, ['assigned_user' => $selectedUser->full_name]);

        $event = DocumentHistory::record($document, DocumentHistory::ASSIGNED, $selectedUser, [
            'from_user_id' => $selectedUser->id,
            'to_user_id' => $selectedUser->id,
        ]);

        Sanctum::actingAs($admin);

        $eventIds = collect($this->getJson("/api/users/{$selectedUser->id}/transaction-history")->json('data'))
            ->pluck('event_id')
            ->all();

        $this->assertSame([$event->id], $eventIds);
    }

    public function test_current_holder_changes_do_not_remove_old_events_and_null_holder_is_safe(): void
    {
        $admin = $this->createSuperAdmin();
        $selectedUser = $this->createUser('selected', 'Selena', 'Selected');
        $laterHolder = $this->createUser('holder', 'Henry', 'Holder');
        $document = $this->createDocumentFor($admin, ['assigned_user' => $selectedUser->full_name]);

        $event = DocumentHistory::record($document, DocumentHistory::ASSIGNED, $admin, [
            'to_user_id' => $selectedUser->id,
        ]);

        $document->update(['assigned_user' => $laterHolder->full_name]);

        $nullHolderDocument = $this->createDocumentFor($selectedUser, ['assigned_user' => null]);
        $nullHolderEvent = DocumentHistory::record($nullHolderDocument, DocumentHistory::REGISTERED, $selectedUser);
        $nullHolderEvent->forceFill(['created_at' => now()->addMinute()])->save();

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/users/{$selectedUser->id}/transaction-history");

        $response->assertOk();

        $rows = collect($response->json('data'))->keyBy('event_id');

        $this->assertTrue($rows->has($event->id));
        $this->assertSame(['id' => null, 'name' => $laterHolder->full_name], $rows[$event->id]['current_holder']);
        $this->assertTrue($rows->has($nullHolderEvent->id));
        $this->assertNull($rows[$nullHolderEvent->id]['current_holder']);
    }

    public function test_unauthorized_viewer_cannot_access_another_users_transaction_history(): void
    {
        $viewer = $this->createUser('viewer', 'Victor', 'Viewer');
        $selectedUser = $this->createUser('selected', 'Selena', 'Selected');

        Sanctum::actingAs($viewer);

        $this->getJson("/api/users/{$selectedUser->id}/transaction-history")->assertForbidden();
    }

    public function test_filters_use_existing_fields_and_confidential_subject_is_redacted_without_permission(): void
    {
        $admin = $this->createSuperAdmin();
        $selectedUser = $this->createUser('selected', 'Selena', 'Selected');
        $holder = $this->createUser('holder', 'Henry', 'Holder');
        $document = $this->createDocumentFor($selectedUser, [
            'description' => 'Confidential appointment memo',
            'document_type' => 'Memo',
            'priority_level' => 'High',
            'status' => 'outgoing',
            'is_confidential' => true,
            'assigned_user' => $holder->full_name,
        ]);
        $document->acknowledgements()->create([
            'user_id' => $holder->id,
            'assigned_name' => $holder->full_name,
        ]);
        $event = DocumentHistory::record($document, DocumentHistory::REGISTERED, $selectedUser, [
            'remarks' => 'Sensitive note.',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/users/{$selectedUser->id}/transaction-history?action=REGISTERED&type=Memo&priority=High&status=outgoing&date_from=".now()->subDay()->toDateString().'&date_to='.now()->addDay()->toDateString());

        $response
            ->assertOk()
            ->assertJsonPath('data.0.event_id', $event->id)
            ->assertJsonPath('data.0.particular', null)
            ->assertJsonPath('data.0.current_holder', null)
            ->assertJsonPath('data.0.remarks', null);

        $permission = Permission::firstOrCreate(
            ['name' => 'documents.view_confidential', 'guard_name' => 'web'],
            [
                'group_name' => 'Documents',
                'description' => 'View confidential documents',
            ]
        );
        $admin->givePermissionTo($permission);

        $this->getJson("/api/users/{$selectedUser->id}/transaction-history?search=Confidential")
            ->assertOk()
            ->assertJsonPath('data.0.particular', 'Confidential appointment memo')
            ->assertJsonPath('data.0.current_holder.name', $holder->full_name)
            ->assertJsonPath('data.0.remarks', 'Sensitive note.');
    }

    public function test_invalid_date_range_is_rejected(): void
    {
        $admin = $this->createSuperAdmin();
        $selectedUser = $this->createUser('selected', 'Selena', 'Selected');

        Sanctum::actingAs($admin);

        $this->getJson("/api/users/{$selectedUser->id}/transaction-history?date_from=2026-08-11&date_to=2026-08-10")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
    }

    public function test_supervisor_authorized_for_user_history_can_open_related_timeline(): void
    {
        [$office, $division] = $this->createOrganization();
        $supervisor = $this->createUser('supervisor', 'Sam', 'Supervisor', [
            'office_id' => $office->id,
            'division_id' => $division->id,
        ]);
        $supervisor->assignRole(Role::firstOrCreate(['name' => 'supervisor', 'guard_name' => 'web']));

        $selectedUser = $this->createUser('selected', 'Selena', 'Selected', [
            'office_id' => $office->id,
            'division_id' => $division->id,
        ]);
        $document = $this->createDocumentFor($selectedUser);
        DocumentHistory::record($document, DocumentHistory::REGISTERED, $selectedUser);

        Sanctum::actingAs($supervisor);

        $this->getJson("/api/users/{$selectedUser->id}/transaction-history")->assertOk();
        $this->getJson("/api/documents/{$document->id}/timeline")->assertOk();
    }

    public function test_unknown_canonical_action_is_returned_safely(): void
    {
        $admin = $this->createSuperAdmin();
        $selectedUser = $this->createUser('selected', 'Selena', 'Selected');
        $document = $this->createDocumentFor($selectedUser);
        $event = $document->histories()->create([
            'tracking_number' => $document->tracking_number,
            'performed_by' => $selectedUser->id,
            'action' => 'TRANSFERRED_TO_OFFICE',
            'status_before' => 'outgoing',
            'status_after' => 'in_process',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/users/{$selectedUser->id}/transaction-history")
            ->assertOk()
            ->assertJsonPath('data.0.event_id', $event->id)
            ->assertJsonPath('data.0.action', 'TRANSFERRED_TO_OFFICE');
    }

    private function createSuperAdmin(): User
    {
        $admin = $this->createUser('admin'.Str::lower(Str::random(5)), 'Ada', 'Admin');
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin->assignRole($role);

        return $admin;
    }

    private function createUser(string $username, string $firstName, string $lastName, array $attributes = []): User
    {
        return User::create([
            ...[
                'username' => $username,
                'password' => Hash::make('password'),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'employee_id' => 'EMP-'.Str::upper(Str::random(6)),
                'must_change_password' => false,
                'is_active' => true,
            ],
            ...$attributes,
        ]);
    }

    private function createOrganization(): array
    {
        $office = Office::create([
            'code' => 'OFF-'.Str::upper(Str::random(5)),
            'name' => 'Test Office',
            'short_name' => 'TO',
        ]);

        $division = Division::create([
            'office_id' => $office->id,
            'code' => 'DIV-'.Str::upper(Str::random(5)),
            'name' => 'Test Division',
            'short_name' => 'TD',
        ]);

        return [$office, $division];
    }

    private function createDocumentFor(User $user, array $attributes = []): Document
    {
        return $user->createdDocuments()->create([
            ...[
                'tracking_number' => 'DOC-'.Str::upper(Str::random(12)),
                'origin_sender' => 'HRMO',
                'sender_name' => $user->full_name,
                'document_type' => 'Memorandum',
                'priority_level' => 'Normal',
                'description' => 'Document transaction history API test.',
                'status' => 'outgoing',
            ],
            ...$attributes,
        ]);
    }
}
