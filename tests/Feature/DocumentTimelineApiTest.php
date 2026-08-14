<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentHistory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentTimelineApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_timeline_returns_canonical_history_chronologically(): void
    {
        $admin = $this->createAdminUser();
        $actor = $this->createUser('registrar', 'Regina', 'Registrar');
        $assignee = $this->createUser('assignee', 'Ariane', 'Assignee');
        $document = $this->createDocumentFor($actor, [
            'description' => 'Timeline payroll memo',
            'document_type' => 'Memo',
            'priority_level' => 'High',
            'status' => 'outgoing',
            'assigned_user' => $assignee->full_name,
        ]);
        $document->acknowledgements()->create([
            'user_id' => $assignee->id,
            'assigned_name' => $assignee->full_name,
        ]);

        $assigned = DocumentHistory::record($document, DocumentHistory::ASSIGNED, $actor, [
            'to_user_id' => $assignee->id,
            'status_before' => 'outgoing',
            'status_after' => 'outgoing',
            'remarks' => 'Assigned for review.',
        ]);
        $assigned->forceFill(['created_at' => now()->addMinute()])->save();

        $registered = DocumentHistory::record($document, DocumentHistory::REGISTERED, $actor, [
            'status_after' => 'outgoing',
            'remarks' => 'Registered first.',
        ]);
        $registered->forceFill(['created_at' => now()])->save();

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/documents/{$document->id}/timeline");

        $response
            ->assertOk()
            ->assertJsonPath('document.id', $document->id)
            ->assertJsonPath('document.tracking_number', $document->tracking_number)
            ->assertJsonPath('document.particular', 'Timeline payroll memo')
            ->assertJsonPath('document.type', 'Memo')
            ->assertJsonPath('document.priority', 'High')
            ->assertJsonPath('document.status', 'outgoing')
            ->assertJsonPath('document.current_holder.name', $assignee->full_name)
            ->assertJsonPath('data.0.event_id', $registered->id)
            ->assertJsonPath('data.0.action', DocumentHistory::REGISTERED)
            ->assertJsonPath('data.0.actor.name', $actor->full_name)
            ->assertJsonPath('data.0.status_after', 'outgoing')
            ->assertJsonPath('data.0.remarks', 'Registered first.')
            ->assertJsonPath('data.1.event_id', $assigned->id)
            ->assertJsonPath('data.1.action', DocumentHistory::ASSIGNED)
            ->assertJsonPath('data.1.actor.name', $actor->full_name)
            ->assertJsonPath('data.1.to_user.name', $assignee->full_name)
            ->assertJsonPath('data.1.remarks', 'Assigned for review.');
    }

    public function test_document_timeline_uses_safe_fallbacks_for_optional_missing_users(): void
    {
        $admin = $this->createAdminUser();
        $actor = $this->createUser('registrar', 'Regina', 'Registrar');
        $document = $this->createDocumentFor($actor, [
            'assigned_user' => 'External Reviewer',
        ]);

        DocumentHistory::record($document, DocumentHistory::ASSIGNED, $actor, [
            'metadata' => ['assigned_name' => 'External Reviewer'],
            'status_before' => 'outgoing',
            'status_after' => 'outgoing',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/documents/{$document->id}/timeline")
            ->assertOk()
            ->assertJsonPath('document.current_holder.name', 'External Reviewer')
            ->assertJsonPath('data.0.from_user', null)
            ->assertJsonPath('data.0.to_user.name', 'External Reviewer')
            ->assertJsonPath('data.0.to_user.id', null);
    }

    public function test_confidential_document_timeline_redacts_sensitive_fields_without_permission(): void
    {
        $admin = $this->createAdminUser();
        $actor = $this->createUser('registrar', 'Regina', 'Registrar');
        $assignee = $this->createUser('assignee', 'Ariane', 'Assignee');
        $document = $this->createDocumentFor($actor, [
            'description' => 'Confidential promotion memo',
            'is_confidential' => true,
            'assigned_user' => $assignee->full_name,
        ]);
        $document->acknowledgements()->create([
            'user_id' => $assignee->id,
            'assigned_name' => $assignee->full_name,
        ]);
        DocumentHistory::record($document, DocumentHistory::ASSIGNED, $actor, [
            'to_user_id' => $assignee->id,
            'remarks' => 'Sensitive assignment reason.',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/documents/{$document->id}/timeline")
            ->assertOk()
            ->assertJsonPath('document.particular', null)
            ->assertJsonPath('document.current_holder', null)
            ->assertJsonPath('document.is_redacted', true)
            ->assertJsonPath('data.0.actor', null)
            ->assertJsonPath('data.0.to_user', null)
            ->assertJsonPath('data.0.remarks', null);

        $permission = Permission::firstOrCreate(
            ['name' => 'documents.view_confidential', 'guard_name' => 'web'],
            [
                'group_name' => 'Documents',
                'description' => 'View confidential documents',
            ]
        );
        $admin->givePermissionTo($permission);

        $this->getJson("/api/documents/{$document->id}/timeline")
            ->assertOk()
            ->assertJsonPath('document.particular', 'Confidential promotion memo')
            ->assertJsonPath('document.current_holder.name', $assignee->full_name)
            ->assertJsonPath('document.is_redacted', false)
            ->assertJsonPath('data.0.actor.name', $actor->full_name)
            ->assertJsonPath('data.0.to_user.name', $assignee->full_name)
            ->assertJsonPath('data.0.remarks', 'Sensitive assignment reason.');
    }

    public function test_unrelated_user_cannot_open_document_timeline(): void
    {
        $actor = $this->createUser('registrar', 'Regina', 'Registrar');
        $unrelated = $this->createUser('viewer', 'Victor', 'Viewer');
        $document = $this->createDocumentFor($actor);
        DocumentHistory::record($document, DocumentHistory::REGISTERED, $actor);

        Sanctum::actingAs($unrelated);

        $this->getJson("/api/documents/{$document->id}/timeline")->assertForbidden();
    }

    public function test_involved_user_can_open_document_timeline(): void
    {
        $actor = $this->createUser('registrar', 'Regina', 'Registrar');
        $document = $this->createDocumentFor($actor);
        DocumentHistory::record($document, DocumentHistory::REGISTERED, $actor);

        Sanctum::actingAs($actor);

        $this->getJson("/api/documents/{$document->id}/timeline")
            ->assertOk()
            ->assertJsonPath('data.0.actor.name', $actor->full_name);
    }

    public function test_unknown_canonical_action_is_returned_safely_in_timeline(): void
    {
        $admin = $this->createAdminUser();
        $actor = $this->createUser('registrar', 'Regina', 'Registrar');
        $document = $this->createDocumentFor($actor);
        $event = $document->histories()->create([
            'tracking_number' => $document->tracking_number,
            'performed_by' => $actor->id,
            'action' => 'TRANSFERRED_TO_OFFICE',
            'status_before' => 'outgoing',
            'status_after' => 'in_process',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/documents/{$document->id}/timeline")
            ->assertOk()
            ->assertJsonPath('data.0.event_id', $event->id)
            ->assertJsonPath('data.0.action', 'TRANSFERRED_TO_OFFICE');
    }

    private function createAdminUser(): User
    {
        $admin = $this->createUser('admin'.Str::lower(Str::random(5)), 'Ada', 'Admin');
        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->assignRole($role);

        return $admin;
    }

    private function createUser(string $username, string $firstName, string $lastName): User
    {
        return User::create([
            'username' => $username,
            'password' => Hash::make('password'),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'employee_id' => 'EMP-'.Str::upper(Str::random(6)),
            'must_change_password' => false,
            'is_active' => true,
        ]);
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
                'description' => 'Document timeline API test.',
                'status' => 'outgoing',
            ],
            ...$attributes,
        ]);
    }
}
