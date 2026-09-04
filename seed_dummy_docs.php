<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Document;
use App\Models\DocumentHistory;
use App\Models\DocumentAttachment;
use App\Models\User;
use Illuminate\Support\Str;

$admin = User::first();
$adminId = $admin ? $admin->id : Str::uuid()->toString();
$adminName = $admin ? ($admin->name ?? $admin->first_name . ' ' . $admin->last_name) : 'Admin User';

echo "Using User: $adminName ($adminId)\n";

// 1. PENDING DOCUMENT
$pendingDoc = Document::updateOrCreate(
    ['tracking_number' => 'DOC-2026-00101'],
    [
        'id' => (string) Str::uuid(),
        'tracking_number' => 'DOC-2026-00101',
        'created_by' => $adminId,
        'origin_sender' => "City Mayor's Office",
        'sender_name' => 'Engr. Roberto Dela Torre',
        'document_type' => 'Transmittal',
        'priority_level' => 'High',
        'description' => 'Request for Plantilla Position Verification and Endorsement',
        'remarks' => 'Urgent review requested before executive committee meeting.',
        'is_confidential' => false,
        'self_assign' => false,
        'send_to_all' => false,
        'is_internal' => true,
        'office_assignment' => 'Human Resource Management Office',
        'division_assignment' => 'Recruitment and Selection Division',
        'assigned_user' => $adminName,
        'is_read_only' => false,
        'status' => 'pending',
        'due_date' => now()->addDays(3)->format('Y-m-d'),
        'notes' => 'Please verify with updated civil service guidelines.',
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subHours(4),
    ]
);

// Clear old histories for this tracking number
DocumentHistory::where('document_id', $pendingDoc->id)->orWhere('tracking_number', 'DOC-2026-00101')->delete();

// Add pending history events
DocumentHistory::create([
    'id' => (string) Str::uuid(),
    'document_id' => $pendingDoc->id,
    'tracking_number' => 'DOC-2026-00101',
    'performed_by' => $adminId,
    'action' => DocumentHistory::REGISTERED,
    'status_before' => null,
    'status_after' => 'pending',
    'remarks' => 'Document registered at City Mayor\'s Office intake window.',
    'created_at' => now()->subDays(2),
]);

DocumentHistory::create([
    'id' => (string) Str::uuid(),
    'document_id' => $pendingDoc->id,
    'tracking_number' => 'DOC-2026-00101',
    'performed_by' => $adminId,
    'action' => DocumentHistory::TRANSFERRED,
    'status_before' => 'pending',
    'status_after' => 'pending',
    'remarks' => 'Transferred from City Mayor\'s Office to Human Resource Management Office.',
    'created_at' => now()->subDay(),
]);

DocumentHistory::create([
    'id' => (string) Str::uuid(),
    'document_id' => $pendingDoc->id,
    'tracking_number' => 'DOC-2026-00101',
    'performed_by' => $adminId,
    'action' => DocumentHistory::RECEIVED,
    'status_before' => 'pending',
    'status_after' => 'pending',
    'remarks' => 'Received by HRMO Records Section for processing.',
    'created_at' => now()->subHours(4),
]);

echo "Created Pending Document: DOC-2026-00101\n";

// 2. COMPLETED DOCUMENT
$completedDoc = Document::updateOrCreate(
    ['tracking_number' => 'DOC-2026-00202'],
    [
        'id' => (string) Str::uuid(),
        'tracking_number' => 'DOC-2026-00202',
        'created_by' => $adminId,
        'origin_sender' => 'City Planning & Development Office',
        'sender_name' => 'Arch. Clara Mendoza',
        'document_type' => 'Endorsement Letter',
        'priority_level' => 'Normal',
        'description' => 'Comprehensive Land Use Plan Staff Clearance & Reassignment',
        'remarks' => 'All requirements cleared and signatures obtained.',
        'is_confidential' => false,
        'self_assign' => false,
        'send_to_all' => false,
        'is_internal' => true,
        'office_assignment' => 'Human Resource Management Office',
        'division_assignment' => 'Personnel Records Division',
        'assigned_user' => $adminName,
        'is_read_only' => false,
        'status' => 'completed',
        'completed_at' => now()->subDays(1),
        'due_date' => now()->subDays(2)->format('Y-m-d'),
        'notes' => 'Completed and archived on digital file.',
        'created_at' => now()->subDays(5),
        'updated_at' => now()->subDays(1),
    ]
);

// Clear old histories for this tracking number
DocumentHistory::where('document_id', $completedDoc->id)->orWhere('tracking_number', 'DOC-2026-00202')->delete();

// Add completed history events
DocumentHistory::create([
    'id' => (string) Str::uuid(),
    'document_id' => $completedDoc->id,
    'tracking_number' => 'DOC-2026-00202',
    'performed_by' => $adminId,
    'action' => DocumentHistory::REGISTERED,
    'status_before' => null,
    'status_after' => 'pending',
    'remarks' => 'Document submitted and registered by CPDO Liaison Officer.',
    'created_at' => now()->subDays(5),
]);

DocumentHistory::create([
    'id' => (string) Str::uuid(),
    'document_id' => $completedDoc->id,
    'tracking_number' => 'DOC-2026-00202',
    'performed_by' => $adminId,
    'action' => DocumentHistory::RECEIVED,
    'status_before' => 'pending',
    'status_after' => 'pending',
    'remarks' => 'Received by HRMO Receiving Officer.',
    'created_at' => now()->subDays(4),
]);

DocumentHistory::create([
    'id' => (string) Str::uuid(),
    'document_id' => $completedDoc->id,
    'tracking_number' => 'DOC-2026-00202',
    'performed_by' => $adminId,
    'action' => DocumentHistory::ASSIGNED,
    'status_before' => 'pending',
    'status_after' => 'pending',
    'remarks' => 'Assigned to HR Personnel Officer for evaluation.',
    'created_at' => now()->subDays(3),
]);

DocumentHistory::create([
    'id' => (string) Str::uuid(),
    'document_id' => $completedDoc->id,
    'tracking_number' => 'DOC-2026-00202',
    'performed_by' => $adminId,
    'action' => DocumentHistory::APPROVED,
    'status_before' => 'pending',
    'status_after' => 'pending',
    'remarks' => 'Approved by City Human Resource Officer.',
    'created_at' => now()->subDays(2),
]);

DocumentHistory::create([
    'id' => (string) Str::uuid(),
    'document_id' => $completedDoc->id,
    'tracking_number' => 'DOC-2026-00202',
    'performed_by' => $adminId,
    'action' => DocumentHistory::COMPLETED,
    'status_before' => 'pending',
    'status_after' => 'completed',
    'remarks' => 'Action finalized. Document marked as Completed.',
    'created_at' => now()->subDays(1),
]);

echo "Created Completed Document: DOC-2026-00202\n";
