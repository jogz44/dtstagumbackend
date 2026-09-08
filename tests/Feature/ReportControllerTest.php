<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentHistory;
use App\Models\Office;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;

    protected User $supervisorUser;

    protected User $staffUser;

    protected Office $officeA;

    protected Office $officeB;

    protected Division $divisionA;

    protected Division $divisionB;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['id' => (string) Str::uuid()]);
        Role::firstOrCreate(['name' => 'supervisor', 'guard_name' => 'web'], ['id' => (string) Str::uuid()]);
        Role::firstOrCreate(['name' => 'receiver', 'guard_name' => 'web'], ['id' => (string) Str::uuid()]);

        $permission = Permission::findOrCreate('reports.view', 'web');
        foreach (['admin', 'supervisor', 'receiver'] as $role) {
            Role::findByName($role, 'web')->givePermissionTo($permission);
        }

        $this->officeA = Office::create(['code' => 'OFF-A', 'name' => 'City Treasurer', 'is_active' => true]);
        $this->officeB = Office::create(['code' => 'OFF-B', 'name' => 'City Planning', 'is_active' => true]);

        $this->divisionA = Division::create(['office_id' => $this->officeA->id, 'code' => 'DIV-A', 'name' => 'Finance Division', 'is_active' => true]);
        $this->divisionB = Division::create(['office_id' => $this->officeB->id, 'code' => 'DIV-B', 'name' => 'Planning Division', 'is_active' => true]);

        $this->adminUser = $this->createUser(['username' => 'admin_user', 'office_id' => $this->officeA->id, 'division_id' => $this->divisionA->id]);
        $this->adminUser->assignRole('admin');

        $this->supervisorUser = $this->createUser(['username' => 'supervisor_user', 'office_id' => $this->officeA->id, 'division_id' => $this->divisionA->id]);
        $this->supervisorUser->assignRole('supervisor');

        $this->staffUser = $this->createUser(['username' => 'staff_user', 'office_id' => $this->officeB->id, 'division_id' => $this->divisionB->id]);
        $this->staffUser->assignRole('receiver');
    }

    protected function createUser(array $overrides = []): User
    {
        static $sequence = 0;
        $sequence++;

        $user = User::create([
            'username' => $overrides['username'] ?? "user_{$sequence}_".Str::random(5),
            'password' => Hash::make('password123'),
            'first_name' => $overrides['first_name'] ?? 'Test',
            'last_name' => $overrides['last_name'] ?? "User {$sequence}",
            'is_active' => $overrides['is_active'] ?? true,
            'office_id' => $overrides['office_id'] ?? null,
            'division_id' => $overrides['division_id'] ?? null,
        ]);
        $user->givePermissionTo(Permission::findByName('reports.view', 'web'));

        return $user;
    }

    /** 1. Multi-office Routing Fixture Test */
    public function test_multi_office_routing_counts_document_as_one_inventory(): void
    {
        $doc = Document::create([
            'tracking_number' => 'DOC-ROUTING-01',
            'created_by' => $this->adminUser->id,
            'origin_sender' => $this->officeA->name,
            'sender_name' => 'Admin',
            'document_type' => 'Letter',
            'priority_level' => 'Normal',
            'description' => 'Multi-office test',
            'office_assignment' => $this->officeB->name,
            'status' => 'in_transit',
        ]);

        DocumentHistory::record($doc, 'REGISTERED', $this->adminUser, ['from_office' => $this->officeA->name, 'to_office' => $this->officeA->name]);
        DocumentHistory::record($doc, 'TRANSFERRED', $this->adminUser, ['from_office' => $this->officeA->name, 'to_office' => $this->officeB->name]);
        DocumentHistory::record($doc, 'RECEIVED', $this->staffUser, ['from_office' => $this->officeB->name, 'to_office' => $this->officeB->name]);

        $response = $this->actingAs($this->adminUser)->getJson('/api/reports');

        $response->assertStatus(200)
            ->assertJsonPath('meta.reconciliation.documents', 1)
            ->assertJsonPath('meta.reconciliation.reconciled', true);
    }

    /** 2. Unassigned Division Fixture Test */
    public function test_unassigned_division_is_bucketed(): void
    {
        Document::create([
            'tracking_number' => 'DOC-UNASSIGNED-DIV',
            'created_by' => $this->adminUser->id,
            'origin_sender' => $this->officeA->name,
            'sender_name' => 'Admin',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'No division',
            'office_assignment' => $this->officeA->name,
            'division_assignment' => null,
            'status' => 'registered',
        ]);

        $response = $this->actingAs($this->adminUser)->getJson('/api/reports');

        $response->assertStatus(200)
            ->assertJsonPath('meta.reconciliation.division_match', true);
    }

    /** 3. Archived & Cancelled Records Fixture Test */
    public function test_archived_records_are_categorized_properly(): void
    {
        Document::create([
            'tracking_number' => 'DOC-ARCHIVED-01',
            'created_by' => $this->adminUser->id,
            'origin_sender' => $this->officeA->name,
            'sender_name' => 'Admin',
            'document_type' => 'Report',
            'priority_level' => 'Normal',
            'description' => 'Archived doc',
            'status' => 'archived',
        ]);

        $response = $this->actingAs($this->adminUser)->getJson('/api/reports');

        $response->assertStatus(200)
            ->assertJsonPath('meta.reconciliation.status_match', true);
    }

    /** 4. Duplicate Tracking ID Ingestion Fixture Test */
    public function test_duplicate_tracking_id_is_rejected(): void
    {
        Document::create([
            'tracking_number' => 'DOC-UNIQUE-100',
            'created_by' => $this->adminUser->id,
            'origin_sender' => 'Office A',
            'sender_name' => 'Admin',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Original',
            'status' => 'registered',
        ]);

        $this->expectException(QueryException::class);

        Document::create([
            'tracking_number' => 'DOC-UNIQUE-100',
            'created_by' => $this->adminUser->id,
            'origin_sender' => 'Office B',
            'sender_name' => 'Staff',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Duplicate',
            'status' => 'registered',
        ]);
    }

    /** 5. Date Boundary Crossing Fixture Test */
    public function test_date_boundary_filtering_uses_utc(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        Document::create([
            'tracking_number' => 'DOC-DATE-BOUNDARY',
            'created_by' => $this->adminUser->id,
            'origin_sender' => 'Office A',
            'sender_name' => 'Admin',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Date test',
            'status' => 'registered',
        ]);

        $response = $this->actingAs($this->adminUser)->getJson('/api/reports?from=2026-08-25&to=2026-08-25');

        $response->assertStatus(200)
            ->assertJsonPath('meta.reconciliation.documents', 1);
    }

    /** 6. Unauthorized Office Access Fixture Test */
    public function test_unauthorized_office_access_is_scoped(): void
    {
        Document::create([
            'tracking_number' => 'DOC-OFFICE-A',
            'created_by' => $this->adminUser->id,
            'origin_sender' => $this->officeA->name,
            'sender_name' => 'Admin',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Office A doc',
            'office_assignment' => $this->officeA->name,
            'status' => 'registered',
        ]);

        Document::create([
            'tracking_number' => 'DOC-OFFICE-B',
            'created_by' => $this->staffUser->id,
            'origin_sender' => $this->officeB->name,
            'sender_name' => 'Staff',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Office B doc',
            'office_assignment' => $this->officeB->name,
            'status' => 'registered',
        ]);

        $response = $this->actingAs($this->supervisorUser)->getJson('/api/reports');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('meta.reconciliation.documents'));
    }

    /** 7. Zero Previous Period Fixture Test */
    public function test_zero_previous_period_returns_new_type(): void
    {
        Document::create([
            'tracking_number' => 'DOC-CURRENT-ONLY',
            'created_by' => $this->adminUser->id,
            'origin_sender' => 'Office A',
            'sender_name' => 'Admin',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Current doc',
            'status' => 'registered',
        ]);

        $response = $this->actingAs($this->adminUser)->getJson('/api/reports');

        $response->assertStatus(200);
        $this->assertEquals('new', $response->json('kpis.total_documents.change_type'));
    }

    /** 8. Status Mapping to Others Fixture Test */
    public function test_unknown_status_maps_to_others(): void
    {
        Document::create([
            'tracking_number' => 'DOC-CUSTOM-STATUS',
            'created_by' => $this->adminUser->id,
            'origin_sender' => 'Office A',
            'sender_name' => 'Admin',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Custom status',
            'status' => 'custom_legacy_status',
        ]);

        $response = $this->actingAs($this->adminUser)->getJson('/api/reports');

        $response->assertStatus(200)
            ->assertJsonPath('meta.reconciliation.reconciled', true);
    }

    /** 9. Renamed Office Legacy Assignment Test */
    public function test_renamed_office_legacy_assignment_handling(): void
    {
        Document::create([
            'tracking_number' => 'DOC-RENAMED-OFFICE',
            'created_by' => $this->adminUser->id,
            'origin_sender' => $this->officeA->name,
            'sender_name' => 'Admin',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Renamed office test',
            'office_assignment' => 'City Treasurer',
            'status' => 'registered',
        ]);

        $response = $this->actingAs($this->adminUser)->getJson('/api/reports');

        $response->assertStatus(200)
            ->assertJsonPath('meta.reconciliation.office_match', true);
    }

    /** 10. Category Status Filter Test */
    public function test_category_status_filter_matches_all_mapped_raw_values(): void
    {
        Document::create([
            'tracking_number' => 'DOC-COMP-1',
            'created_by' => $this->adminUser->id,
            'origin_sender' => 'Office A',
            'sender_name' => 'Admin',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Completed 1',
            'status' => 'completed',
        ]);

        Document::create([
            'tracking_number' => 'DOC-COMP-2',
            'created_by' => $this->adminUser->id,
            'origin_sender' => 'Office A',
            'sender_name' => 'Admin',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Released 2',
            'status' => 'released',
        ]);

        $response = $this->actingAs($this->adminUser)->getJson('/api/reports?status=Completed');

        $response->assertStatus(200)
            ->assertJsonPath('meta.reconciliation.documents', 2);
    }

    /** 11. Soft-Deleted Document Isolation Test */
    public function test_soft_deleted_documents_are_excluded_from_operational_reports(): void
    {
        $doc = Document::create([
            'tracking_number' => 'DOC-SOFT-DELETE',
            'created_by' => $this->adminUser->id,
            'origin_sender' => 'Office A',
            'sender_name' => 'Admin',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'To be deleted',
            'status' => 'registered',
        ]);

        $doc->delete();

        $response = $this->actingAs($this->adminUser)->getJson('/api/reports');

        $response->assertStatus(200)
            ->assertJsonPath('meta.reconciliation.documents', 0);
    }

    /** 12. Exact Date Boundary Exclusion Test */
    public function test_exact_date_boundary_exclusion(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');

        $docBoundary = Document::create([
            'tracking_number' => 'DOC-NEXT-DAY-BOUNDARY',
            'created_by' => $this->adminUser->id,
            'origin_sender' => 'Office A',
            'sender_name' => 'Admin',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Next day boundary',
            'status' => 'registered',
        ]);

        $docBoundary->created_at = Carbon::parse('2026-08-26 00:00:01', 'Asia/Manila')->setTimezone('UTC');
        $docBoundary->save();

        $response = $this->actingAs($this->adminUser)->getJson('/api/reports?from=2026-08-25&to=2026-08-25');

        $response->assertStatus(200)
            ->assertJsonPath('meta.reconciliation.documents', 0);
    }

    /** 13. Strict Authorization Scope Combination Test */
    public function test_strict_authorization_scope_does_not_broaden_access(): void
    {
        Document::create([
            'tracking_number' => 'DOC-STAFF-OWNED',
            'created_by' => $this->staffUser->id,
            'origin_sender' => $this->officeB->name,
            'sender_name' => 'Staff',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Staff doc',
            'office_assignment' => $this->officeB->name,
            'status' => 'registered',
        ]);

        $response = $this->actingAs($this->supervisorUser)->getJson('/api/reports');

        $response->assertStatus(200)
            ->assertJsonPath('meta.reconciliation.documents', 0);
    }

    /** 14. Direct Scoped SQL Reconciliation Assertion */
    public function test_direct_scoped_sql_reconciliation_matches_api_meta(): void
    {
        Document::create([
            'tracking_number' => 'DOC-SCOPED-SQL',
            'created_by' => $this->adminUser->id,
            'origin_sender' => $this->officeA->name,
            'sender_name' => 'Admin',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'SQL Scoped test',
            'office_assignment' => $this->officeA->name,
            'status' => 'registered',
        ]);

        $response = $this->actingAs($this->adminUser)->getJson('/api/reports');

        $apiCount = $response->json('meta.reconciliation.documents');
        $directDbCount = DB::table('documents')->whereNull('deleted_at')->count();

        $this->assertEquals($directDbCount, $apiCount);
    }

    /** 15. All-Unassigned Reconciliation Test */
    public function test_all_unassigned_documents_reconcile_properly(): void
    {
        Document::create([
            'tracking_number' => 'DOC-ALL-UNASSIGNED',
            'created_by' => $this->adminUser->id,
            'origin_sender' => 'External',
            'sender_name' => 'Guest',
            'document_type' => 'Letter',
            'priority_level' => 'Normal',
            'description' => 'Unassigned doc',
            'office_assignment' => null,
            'division_assignment' => null,
            'status' => 'registered',
        ]);

        $response = $this->actingAs($this->adminUser)->getJson('/api/reports');

        $response->assertStatus(200)
            ->assertJsonPath('meta.reconciliation.reconciled', true);
    }

    /** 16. Search + Permission Interaction Test */
    public function test_search_respects_user_permission_isolation(): void
    {
        Document::create([
            'tracking_number' => 'DOC-CONFIDENTIAL-SEARCH',
            'created_by' => $this->adminUser->id,
            'origin_sender' => $this->officeA->name,
            'sender_name' => 'Admin',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Secret project',
            'office_assignment' => $this->officeA->name,
            'status' => 'registered',
        ]);

        $response = $this->actingAs($this->staffUser)->getJson('/api/reports?search=Secret');

        $response->assertStatus(200)
            ->assertJsonPath('meta.reconciliation.documents', 0);
    }

    /** 17. Pagination Consistency Test */
    public function test_pagination_total_items_matches_unpaginated_count(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            Document::create([
                'tracking_number' => "DOC-PAGINATE-{$i}",
                'created_by' => $this->adminUser->id,
                'origin_sender' => 'Office A',
                'sender_name' => 'Admin',
                'document_type' => 'Report',
                'priority_level' => 'Normal',
                'description' => "Doc {$i}",
                'status' => 'registered',
            ]);
        }

        $response = $this->actingAs($this->adminUser)->getJson('/api/reports?per_page=2');

        $response->assertStatus(200)
            ->assertJsonPath('documents_sent.pagination.total_items', 5)
            ->assertJsonPath('meta.reconciliation.documents', 5);
    }

    /** 18. Concurrent Update Consistency Test */
    public function test_concurrent_status_update_does_not_duplicate_document_count(): void
    {
        $doc = Document::create([
            'tracking_number' => 'DOC-CONCURRENT-UPDATE',
            'created_by' => $this->adminUser->id,
            'origin_sender' => 'Office A',
            'sender_name' => 'Admin',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Concurrent test',
            'status' => 'registered',
        ]);

        $doc->update(['status' => 'in_transit', 'office_assignment' => $this->officeB->name]);

        $response = $this->actingAs($this->adminUser)->getJson('/api/reports');

        $response->assertStatus(200)
            ->assertJsonPath('meta.reconciliation.documents', 1);
    }

    /** 19. Role Slug Resolution Test */
    public function test_spatie_role_slugs_resolve_correctly(): void
    {
        $responseAdmin = $this->actingAs($this->adminUser)->getJson('/api/reports');
        $responseSupervisor = $this->actingAs($this->supervisorUser)->getJson('/api/reports');
        $responseStaff = $this->actingAs($this->staffUser)->getJson('/api/reports');

        $responseAdmin->assertStatus(200);
        $responseSupervisor->assertStatus(200);
        $responseStaff->assertStatus(200);
    }

    /** 20. SoftDeletes Model Scope Test */
    public function test_document_model_soft_deletes_scope_behavior(): void
    {
        $doc = Document::create([
            'tracking_number' => 'DOC-MODEL-SOFT-DELETE',
            'created_by' => $this->adminUser->id,
            'origin_sender' => 'Office A',
            'sender_name' => 'Admin',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Soft delete model test',
            'status' => 'registered',
        ]);

        $this->assertEquals(1, Document::count());
        $doc->delete();
        $this->assertEquals(0, Document::count());
        $this->assertEquals(1, Document::withTrashed()->count());
    }

    /** 21. Assigned User Identity Test */
    public function test_assigned_user_identity_uuid_isolation(): void
    {
        $user1 = $this->createUser(['first_name' => 'Same', 'last_name' => 'Name']);
        $user2 = $this->createUser(['first_name' => 'Same', 'last_name' => 'Name']);

        Document::create([
            'tracking_number' => 'DOC-ASSIGNED-UUID',
            'created_by' => $this->adminUser->id,
            'origin_sender' => 'Office A',
            'sender_name' => 'Admin',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'UUID isolation test',
            'assigned_user' => $user1->id,
            'status' => 'registered',
        ]);

        $response1 = $this->actingAs($user1)->getJson('/api/reports');
        $response2 = $this->actingAs($user2)->getJson('/api/reports');

        $response1->assertJsonPath('meta.reconciliation.documents', 1);
        $response2->assertJsonPath('meta.reconciliation.documents', 0);
    }

    /** 22. Independent Office/Division Schema Test */
    public function test_invalid_office_id_validation_throws_error(): void
    {
        $response = $this->actingAs($this->adminUser)->getJson('/api/reports?office_id=invalid-uuid-1234');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['office_id']);
    }

    /** 23. Supervisor Without Office Safety Test */
    public function test_supervisor_without_office_returns_zero_documents(): void
    {
        $unassignedSupervisor = $this->createUser(['office_id' => null, 'division_id' => null]);
        $unassignedSupervisor->assignRole('supervisor');

        Document::create([
            'tracking_number' => 'DOC-SUPERVISOR-SAFETY',
            'created_by' => $this->adminUser->id,
            'origin_sender' => 'Office A',
            'sender_name' => 'Admin',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Unassigned supervisor test',
            'office_assignment' => $this->officeA->name,
            'status' => 'registered',
        ]);

        $response = $this->actingAs($unassignedSupervisor)->getJson('/api/reports');

        $response->assertStatus(200)
            ->assertJsonPath('meta.reconciliation.documents', 0);
    }

    /** 24. Office/Division Hierarchy Validation Test */
    public function test_mismatched_division_for_office_throws_validation_error(): void
    {
        $url = "/api/reports?office_id={$this->officeA->id}&division_id={$this->divisionB->id}";
        $response = $this->actingAs($this->adminUser)->getJson($url);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['division_id']);
    }

    /** 25. Reports Dashboard Endpoint Schema Stability Test */
    public function test_reports_dashboard_endpoint_returns_stable_approved_schema(): void
    {
        $response = $this->actingAs($this->adminUser)->getJson('/api/reports/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'filters',
                'kpis' => ['total_documents', 'active_users', 'total_offices', 'total_divisions'],
                'documents_by_office' => ['top_office', 'items'],
                'documents_by_division' => ['top_division', 'items'],
                'status_breakdown',
                'documents_sent' => ['data', 'pagination'],
                'office_division_overview' => ['by_office', 'by_division'],
                'office_summary_cards',
                'meta' => [
                    'generated_at',
                    'reporting_date_field',
                    'permissions' => ['can_filter_office', 'can_filter_division', 'scope_level'],
                    'available_filters' => ['offices', 'divisions', 'priorities', 'statuses'],
                    'reconciliation' => ['documents', 'statuses', 'offices', 'divisions', 'reconciled'],
                ],
            ]);
    }

    /** 26. Dashboard Component Shared Filter Scope Test */
    public function test_dashboard_components_share_normalized_filter_scope(): void
    {
        Document::create([
            'tracking_number' => 'DOC-FILTER-SCOPE-1',
            'created_by' => $this->adminUser->id,
            'origin_sender' => $this->officeA->name,
            'sender_name' => 'Admin',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Target document',
            'office_assignment' => $this->officeA->name,
            'status' => 'completed',
        ]);

        Document::create([
            'tracking_number' => 'DOC-FILTER-SCOPE-2',
            'created_by' => $this->adminUser->id,
            'origin_sender' => $this->officeA->name,
            'sender_name' => 'Admin',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Other document',
            'office_assignment' => $this->officeA->name,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->adminUser)->getJson('/api/reports/dashboard?status=Completed');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('kpis.total_documents.value'));
        $this->assertEquals(1, $response->json('meta.reconciliation.documents'));
    }

    /** 27. Pagination Metadata Assertion Test */
    public function test_pagination_metadata_matches_filtered_document_set(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            Document::create([
                'tracking_number' => "DOC-PAG-TEST-{$i}",
                'created_by' => $this->adminUser->id,
                'origin_sender' => $this->officeA->name,
                'sender_name' => 'Admin',
                'document_type' => 'Letter',
                'priority_level' => 'Normal',
                'description' => "Filtered doc {$i}",
                'office_assignment' => $this->officeA->name,
                'status' => 'registered',
            ]);
        }

        $response = $this->actingAs($this->adminUser)->getJson('/api/reports/dashboard?per_page=2');

        $response->assertStatus(200)
            ->assertJsonPath('documents_sent.pagination.total_items', 3)
            ->assertJsonPath('documents_sent.pagination.total_pages', 2);
    }

    /** 28. Reconciliation Status Flag Assertion Test */
    public function test_reconciliation_meta_reconciled_remains_true(): void
    {
        Document::create([
            'tracking_number' => 'DOC-RECON-CHECK',
            'created_by' => $this->adminUser->id,
            'origin_sender' => $this->officeA->name,
            'sender_name' => 'Admin',
            'document_type' => 'Report',
            'priority_level' => 'Normal',
            'description' => 'Reconciliation test',
            'office_assignment' => $this->officeA->name,
            'status' => 'registered',
        ]);

        $response = $this->actingAs($this->adminUser)->getJson('/api/reports/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('meta.reconciliation.reconciled', true)
            ->assertJsonPath('meta.reconciliation.status_match', true)
            ->assertJsonPath('meta.reconciliation.office_match', true)
            ->assertJsonPath('meta.reconciliation.division_match', true);
    }

    /** 29. Offices Report Endpoint & Unassigned Sentinel Test */
    public function test_offices_report_returns_aggregated_data_and_handles_unassigned(): void
    {
        Document::create([
            'tracking_number' => 'DOC-OFF-1',
            'created_by' => $this->adminUser->id,
            'origin_sender' => $this->officeA->name,
            'sender_name' => 'Admin',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Office A Doc',
            'office_assignment' => $this->officeA->name,
            'status' => 'registered',
        ]);

        Document::create([
            'tracking_number' => 'DOC-UNASSIGNED-1',
            'created_by' => $this->adminUser->id,
            'origin_sender' => 'External',
            'sender_name' => 'Visitor',
            'document_type' => 'Letter',
            'priority_level' => 'High',
            'description' => 'Unassigned Doc',
            'office_assignment' => null,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->adminUser)->getJson('/api/reports/offices');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'office_id',
                        'office_name',
                        'documents',
                        'completed',
                        'in_progress',
                        'pending',
                        'returned',
                        'cancelled',
                        'archived',
                        'last_activity',
                    ],
                ],
                'meta' => ['current_page', 'per_page', 'total'],
            ]);

        $data = $response->json('data');
        $officeNames = array_column($data, 'office_name');
        $this->assertContains('Unassigned Office', $officeNames);
        $this->assertContains($this->officeA->name, $officeNames);
    }

    /** 30. Divisions Report Endpoint Test */
    public function test_divisions_report_returns_aggregated_data(): void
    {
        Document::create([
            'tracking_number' => 'DOC-DIV-1',
            'created_by' => $this->adminUser->id,
            'origin_sender' => $this->officeA->name,
            'sender_name' => 'Admin',
            'document_type' => 'Memo',
            'priority_level' => 'Normal',
            'description' => 'Division A Doc',
            'office_assignment' => $this->officeA->name,
            'division_assignment' => $this->divisionA->name,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($this->adminUser)->getJson('/api/reports/divisions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'division_id',
                        'division_name',
                        'office_id',
                        'office_name',
                        'documents',
                        'completed',
                        'in_progress',
                        'pending',
                        'returned',
                        'cancelled',
                        'archived',
                        'last_activity',
                    ],
                ],
                'meta' => ['current_page', 'per_page', 'total'],
            ]);
    }

    /** 31. Document Drill-down Unassigned Sentinel & Parity Test */
    public function test_document_drill_down_unassigned_sentinel_and_aggregate_parity(): void
    {
        Document::create([
            'tracking_number' => 'DOC-UNASSIGNED-PARITY',
            'created_by' => $this->adminUser->id,
            'origin_sender' => 'Unknown',
            'sender_name' => 'Anon',
            'document_type' => 'Notice',
            'priority_level' => 'Normal',
            'description' => 'Parity doc',
            'office_assignment' => null,
            'status' => 'pending',
        ]);

        $officesReport = $this->actingAs($this->adminUser)->getJson('/api/reports/offices');
        $unassignedRow = collect($officesReport->json('data'))->firstWhere('office_name', 'Unassigned Office');

        $this->assertNotNull($unassignedRow);
        $expectedCount = $unassignedRow['documents'];

        $drillDown = $this->actingAs($this->adminUser)->getJson('/api/documents?tab=registry&office_id=unassigned');
        $drillDown->assertStatus(200);

        $this->assertCount($expectedCount, $drillDown->json('data'));
    }

    /** 32. Automated Reconciliation Test: Offices endpoint vs Dashboard */
    public function test_offices_endpoint_returns_same_document_universe_as_dashboard(): void
    {
        Document::create([
            'tracking_number' => 'DOC-RECON-OFF-1',
            'created_by' => $this->adminUser->id,
            'origin_sender' => $this->officeA->name,
            'sender_name' => 'Admin',
            'document_type' => 'Letter',
            'priority_level' => 'High',
            'description' => 'Reconciliation test 1',
            'office_assignment' => $this->officeA->name,
            'status' => 'in_progress',
        ]);

        $dashboard = $this->actingAs($this->adminUser)->getJson('/api/reports');
        $dashboard->assertStatus(200);

        $offices = $this->actingAs($this->adminUser)->getJson('/api/reports/offices');
        $offices->assertStatus(200);

        $dashboardTotal = $dashboard->json('meta.reconciliation.documents');
        $officeTotal = collect($offices->json('data'))->sum('documents');

        $this->assertGreaterThan(0, $dashboardTotal);
        $this->assertSame($dashboardTotal, $officeTotal);
    }

    /** 33. Automated Reconciliation Test: Divisions endpoint vs Dashboard */
    public function test_divisions_endpoint_returns_same_document_universe_as_dashboard(): void
    {
        Document::create([
            'tracking_number' => 'DOC-RECON-DIV-1',
            'created_by' => $this->adminUser->id,
            'origin_sender' => $this->officeA->name,
            'sender_name' => 'Admin',
            'document_type' => 'Letter',
            'priority_level' => 'High',
            'description' => 'Reconciliation test 2',
            'office_assignment' => $this->officeA->name,
            'division_assignment' => $this->divisionA->name,
            'status' => 'completed',
        ]);

        $dashboard = $this->actingAs($this->adminUser)->getJson('/api/reports');
        $dashboard->assertStatus(200);

        $divisions = $this->actingAs($this->adminUser)->getJson('/api/reports/divisions');
        $divisions->assertStatus(200);

        $dashboardTotal = $dashboard->json('meta.reconciliation.documents');
        $divisionTotal = collect($divisions->json('data'))->sum('documents');

        $this->assertGreaterThan(0, $dashboardTotal);
        $this->assertSame($dashboardTotal, $divisionTotal);
    }
}
