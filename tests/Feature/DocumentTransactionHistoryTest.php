<?php

namespace Tests\Feature;

use App\Models\DocumentHistory;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentTransactionHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_registration_creates_canonical_transaction_history_event(): void
    {
        $user = User::create([
            'username' => 'registrar',
            'password' => Hash::make('password'),
            'first_name' => 'Regina',
            'last_name' => 'Registrar',
            'employee_id' => 'EMP-REG',
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $user->givePermissionTo(Permission::findOrCreate('can_add_documents', 'web'));
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/documents', [
            'origin_sender' => 'HRMO',
            'sender_name' => 'Regina Registrar',
            'document_type' => 'Memorandum',
            'priority_level' => 'Normal',
            'description' => 'Document registration test.',
            'remarks' => 'Initial filing.',
            'office_assignment' => 'BAC',
            'division_assignment' => 'BAC Secretariat',
        ]);

        $response->assertCreated();

        $document = $response->json('document');

        $this->assertDatabaseHas('document_histories', [
            'document_id' => $document['id'],
            'tracking_number' => $document['tracking_number'],
            'performed_by' => $user->id,
            'action' => 'REGISTERED',
            'status_before' => null,
            'status_after' => 'pending',
            'from_office' => 'HRMO',
        ]);
    }

    public function test_registered_actor_is_involved_in_document_history(): void
    {
        $user = $this->createUser('registrar', 'Regina', 'Registrar');
        $unrelated = $this->createUser('viewer', 'Una', 'Related');
        $document = $this->createDocumentFor($user);

        $history = DocumentHistory::record($document, DocumentHistory::REGISTERED, $user, [
            'status_after' => 'outgoing',
        ]);

        $this->assertTrue(DocumentHistory::query()->involvingUser($user)->whereKey($history->id)->exists());
        $this->assertFalse(DocumentHistory::query()->involvingUser($unrelated)->whereKey($history->id)->exists());
    }

    public function test_assigned_user_and_assignment_actor_are_involved_in_assigned_history(): void
    {
        $actor = $this->createUser('registrar', 'Regina', 'Registrar');
        $assignee = $this->createUser('assignee', 'Ariane', 'Assignee');
        $unrelated = $this->createUser('viewer', 'Una', 'Related');
        $document = $this->createDocumentFor($actor, ['assigned_user' => $assignee->full_name]);

        $history = DocumentHistory::record($document, DocumentHistory::ASSIGNED, $actor, [
            'to_user_id' => $assignee->id,
            'status_before' => 'outgoing',
            'status_after' => 'outgoing',
        ]);

        $this->assertTrue(DocumentHistory::query()->involvingUser($actor->id)->whereKey($history->id)->exists());
        $this->assertTrue(DocumentHistory::query()->involvingUser($assignee->id)->whereKey($history->id)->exists());
        $this->assertFalse(DocumentHistory::query()->involvingUser($unrelated->id)->whereKey($history->id)->exists());
    }

    public function test_later_current_holder_change_does_not_remove_historical_involvement(): void
    {
        $actor = $this->createUser('registrar', 'Regina', 'Registrar');
        $originalAssignee = $this->createUser('assignee', 'Ariane', 'Assignee');
        $laterHolder = $this->createUser('holder', 'Henry', 'Holder');
        $document = $this->createDocumentFor($actor, ['assigned_user' => $originalAssignee->full_name]);

        $history = DocumentHistory::record($document, DocumentHistory::ASSIGNED, $actor, [
            'to_user_id' => $originalAssignee->id,
            'status_before' => 'outgoing',
            'status_after' => 'outgoing',
        ]);

        $document->update(['assigned_user' => $laterHolder->full_name]);

        $this->assertTrue(DocumentHistory::query()->involvingUser($originalAssignee)->whereKey($history->id)->exists());
        $this->assertFalse(DocumentHistory::query()->involvingUser($laterHolder)->whereKey($history->id)->exists());
    }

    public function test_involving_user_scope_does_not_duplicate_one_event_when_user_has_multiple_roles(): void
    {
        $user = $this->createUser('registrar', 'Regina', 'Registrar');
        $document = $this->createDocumentFor($user, ['assigned_user' => $user->full_name]);

        $history = DocumentHistory::record($document, DocumentHistory::ASSIGNED, $user, [
            'from_user_id' => $user->id,
            'to_user_id' => $user->id,
            'status_before' => 'outgoing',
            'status_after' => 'outgoing',
        ]);

        $ids = DocumentHistory::query()->involvingUser($user)->pluck('id')->all();

        $this->assertSame([$history->id], $ids);
    }

    public function test_document_registration_with_assignee_creates_one_assigned_transaction_history_event(): void
    {
        $actor = User::create([
            'username' => 'registrar',
            'password' => Hash::make('password'),
            'first_name' => 'Regina',
            'last_name' => 'Registrar',
            'employee_id' => 'EMP-REG',
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $assignee = User::create([
            'username' => 'assignee',
            'password' => Hash::make('password'),
            'first_name' => 'Ariane',
            'last_name' => 'Assignee',
            'employee_id' => 'EMP-ASG',
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $actor->givePermissionTo(Permission::findOrCreate('can_add_documents', 'web'));
        Sanctum::actingAs($actor);

        $response = $this->postJson('/api/documents', [
            'origin_sender' => 'HRMO',
            'sender_name' => 'Regina Registrar',
            'document_type' => 'Memorandum',
            'priority_level' => 'Normal',
            'description' => 'Document assignment test.',
            'office_assignment' => 'BAC',
            'division_assignment' => 'BAC Secretariat',
            'assigned_user' => $assignee->id,
        ]);

        $response->assertCreated();

        $document = $response->json('document');

        $this->assertDatabaseHas('document_acknowledgements', [
            'document_id' => $document['id'],
            'user_id' => $assignee->id,
            'assigned_name' => 'Ariane Assignee',
        ]);

        $this->assertDatabaseHas('document_histories', [
            'document_id' => $document['id'],
            'tracking_number' => $document['tracking_number'],
            'performed_by' => $actor->id,
            'to_user_id' => $assignee->id,
            'action' => 'REGISTERED',
            'status_before' => null,
            'status_after' => 'pending',
        ]);

        $this->assertSame(
            1,
            DocumentHistory::query()
                ->where('document_id', $document['id'])
                ->where('action', 'REGISTERED')
                ->count()
        );
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

    private function createDocumentFor(User $user, array $attributes = [])
    {
        return $user->createdDocuments()->create([
            ...[
                'tracking_number' => 'DOC-'.Str::upper(Str::random(12)),
                'origin_sender' => 'HRMO',
                'sender_name' => $user->full_name,
                'document_type' => 'Memorandum',
                'priority_level' => 'Normal',
                'description' => 'Document involvement rule test.',
                'status' => 'outgoing',
            ],
            ...$attributes,
        ]);
    }
}
