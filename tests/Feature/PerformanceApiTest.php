<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\DocumentAssignmentService;
use App\Services\DocumentStatusService;
use App\Services\PerformanceCalculationService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PerformanceApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--no-interaction' => true]);
    }

    public function test_assignment_reassignment_status_and_completion_are_persisted(): void
    {
        $creator = $this->makeUser('creator');
        $firstAssignee = $this->makeUser('first-assignee');
        $completingUser = $this->makeUser('completer');
        $document = $this->makeDocument($creator, [
            'status' => 'received',
            'received_at' => Carbon::parse('2026-08-01 09:00:00'),
            'due_at' => Carbon::parse('2026-08-05 17:00:00'),
        ]);

        $assignments = app(DocumentAssignmentService::class);
        $statuses = app(DocumentStatusService::class);
        $first = $assignments->assign($document, $firstAssignee, $creator, Carbon::parse('2026-08-01 10:00:00'));
        $second = $assignments->assign($document, $completingUser, $creator, Carbon::parse('2026-08-02 11:00:00'));
        $statuses->transition($document, 'processing', $completingUser, 'Work started.');
        $completionTime = Carbon::parse('2026-08-04 15:00:00');
        $completion = $statuses->complete($document, $completingUser, $completionTime);

        $this->assertFalse($first->fresh()->is_current);
        $this->assertSame($second->id, $document->fresh()->assignments()->where('is_current', true)->value('id'));
        $this->assertSame('completed', $document->fresh()->status);
        $this->assertTrue($document->fresh()->completed_at->equalTo($completionTime));
        $this->assertSame($completingUser->id, $document->fresh()->completed_by_user_id);
        $this->assertSame('completed', $completion->to_status);
        $this->assertCount(2, $document->fresh()->assignments);
        $this->assertCount(2, $document->fresh()->statusHistory);
    }

    public function test_calculations_use_authoritative_timestamps_and_exclude_missing_deadlines(): void
    {
        $user = $this->makeUser('processor');
        $periodStart = Carbon::parse('2026-08-01 00:00:00');
        $periodEnd = Carbon::parse('2026-08-31 23:59:59');

        $this->makeCompletedDocument($user, 'DOC-ON-TIME', '2026-08-01 09:00:00', '2026-08-05 17:00:00', '2026-08-04 15:00:00');
        $this->makeCompletedDocument($user, 'DOC-LATE', '2026-08-02 09:00:00', '2026-08-05 17:00:00', '2026-08-06 15:00:00');
        $this->makeCompletedDocument($user, 'DOC-NO-DUE', '2026-08-03 09:00:00', null, '2026-08-03 12:00:00');

        $metrics = app(PerformanceCalculationService::class)->forUser($user->id, $periodStart, $periodEnd);

        $this->assertSame(3, $metrics['documents_completed']);
        $this->assertSame(219600.0, $metrics['average_processing_seconds']);
        $this->assertSame(50.0, $metrics['on_time_rate']);
        $this->assertSame(1, $metrics['overdue_completed_count']);
        $this->assertSame(3, $metrics['productivity_completed_count']);
    }

    public function test_performance_apis_return_database_derived_data(): void
    {
        $user = $this->makeAuthorizedUser('authorized');
        $this->makeCompletedDocument($user, 'DOC-API', '2026-08-01 09:00:00', '2026-08-05 17:00:00', '2026-08-04 15:00:00');

        $query = ['start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'per_page' => 10];

        $ranking = $this->actingAs($user, 'sanctum')->getJson('/api/performance/ranking?'.http_build_query($query));
        $ranking->assertOk()->assertJsonPath('data.0.documents_completed', 1);

        $summary = $this->actingAs($user, 'sanctum')->getJson('/api/performance/summary?'.http_build_query($query));
        $summary->assertOk()->assertJsonPath('data.total_completed_documents', 1);

        $profile = $this->actingAs($user, 'sanctum')->getJson('/api/performance/users/'.$user->id.'?'.http_build_query($query));
        $profile->assertOk()->assertJsonPath('data.documents_completed', 1)->assertJsonPath('data.overall_score', null);

        $tasks = $this->actingAs($user, 'sanctum')->getJson('/api/performance/users/'.$user->id.'/completed-documents?'.http_build_query($query));
        $tasks->assertOk()->assertJsonPath('data.0.tracking_number', 'DOC-API')->assertJsonPath('data.0.on_time', true);
    }

    public function test_performance_api_requires_existing_permission(): void
    {
        $user = $this->makeUser('unauthorized');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/performance/ranking')
            ->assertForbidden();
    }

    private function makeAuthorizedUser(string $username): User
    {
        $user = $this->makeUser($username);
        $permission = Permission::firstOrCreate(['name' => 'performance_ranking.view', 'guard_name' => 'web'], [
            'group_name' => 'Reports & Monitoring',
            'description' => 'View performance ranking',
        ]);
        $role = Role::create([
            'name' => 'performance-viewer',
            'guard_name' => 'web',
            'display_name' => 'Performance Viewer',
            'description' => 'Test performance viewer',
            'is_system' => false,
        ]);
        $role->givePermissionTo($permission);
        $user->assignRole($role);

        return $user;
    }

    private function makeCompletedDocument(User $user, string $trackingNumber, string $receivedAt, ?string $dueAt, string $completedAt): Document
    {
        return $this->makeDocument($user, [
            'tracking_number' => $trackingNumber,
            'status' => 'completed',
            'received_at' => Carbon::parse($receivedAt),
            'due_at' => $dueAt === null ? null : Carbon::parse($dueAt),
            'completed_at' => Carbon::parse($completedAt),
            'completed_by_user_id' => $user->id,
        ]);
    }

    private function makeDocument(User $creator, array $attributes = []): Document
    {
        return Document::create(array_merge([
            'tracking_number' => 'DOC-'.strtoupper(fake()->unique()->bothify('????-####')),
            'created_by' => $creator->id,
            'origin_sender' => 'Human Resources',
            'sender_name' => 'HR Office',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Performance API test document',
            'status' => 'received',
        ], $attributes));
    }

    private function makeUser(string $username): User
    {
        return User::create([
            'username' => $username,
            'password' => 'password',
            'first_name' => 'Test',
            'last_name' => $username,
            'is_active' => true,
            'must_change_password' => false,
        ]);
    }
}
