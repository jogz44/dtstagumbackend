<?php

namespace App\Http\Controllers;

use App\Models\Division;
use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $totalDocuments = Document::count();
        $activeUsersCount = User::where('is_active', true)->count();
        $totalOffices = Office::count();
        $totalDivisions = Division::count();

        $kpiCards = [
            [
                'label' => 'Total Documents',
                'value' => (string) $totalDocuments,
                'note' => 'System wide registered documents',
                'indicator' => 'bg-green',
            ],
            [
                'label' => 'Active Users',
                'value' => (string) $activeUsersCount,
                'note' => "Across {$totalOffices} offices and {$totalDivisions} divisions",
                'indicator' => 'bg-blue',
            ],
            [
                'label' => 'Total Offices',
                'value' => (string) $totalOffices,
                'note' => 'Active organizational offices',
                'indicator' => 'bg-amber',
            ],
            [
                'label' => 'Total Divisions',
                'value' => (string) $totalDivisions,
                'note' => 'Active operational divisions',
                'indicator' => 'bg-purple',
            ],
        ];

        $completedCount = Document::where('status', 'Completed')->orWhere('status', 'RELEASED')->count();
        $inProgressCount = Document::where('status', 'In Progress')->orWhere('status', 'IN_TRANSIT')->count();
        $pendingCount = Document::where('status', 'Pending')->orWhere('status', 'RECEIVED')->count();
        $otherCount = max(0, $totalDocuments - ($completedCount + $inProgressCount + $pendingCount));

        $breakdownPanels = [
            [
                'key' => 'document-status',
                'category' => 'Documents',
                'title' => 'Status Breakdown',
                'total' => $totalDocuments,
                'totalLabel' => 'Total Docs',
                'items' => [
                    ['label' => 'Completed', 'value' => $completedCount, 'colorHex' => '#4caf50'],
                    ['label' => 'In Progress', 'value' => $inProgressCount, 'colorHex' => '#2196f3'],
                    ['label' => 'Pending', 'value' => $pendingCount, 'colorHex' => '#ff9800'],
                    ['label' => 'Others', 'value' => $otherCount, 'colorHex' => '#9e9e9e'],
                ],
            ],
        ];

        $divisionBlocks = Division::withCount('users')
            ->limit(6)
            ->get()
            ->map(function ($division) {
                return [
                    'title' => $division->name,
                    'total' => $division->users_count,
                    'color' => 'bg-green',
                    'items' => [
                        ['label' => 'Users', 'value' => $division->users_count],
                    ],
                ];
            })
            ->toArray();

        $recentDocuments = Document::latest()
            ->limit(10)
            ->get()
            ->map(function ($doc) {
                return [
                    'id' => $doc->id,
                    'trackingId' => $doc->tracking_number,
                    'title' => $doc->description ?? $doc->document_type ?? 'Untitled Document',
                    'division' => $doc->division_assignment ?? 'Unassigned',
                    'priority' => $doc->priority_level ?? 'Normal',
                    'status' => $doc->status ?? 'Pending',
                    'received' => $doc->created_at?->toIso8601String(),
                ];
            })
            ->toArray();

        return response()->json([
            'kpiCards' => $kpiCards,
            'breakdownPanels' => $breakdownPanels,
            'divisionBlocks' => $divisionBlocks,
            'documents' => $recentDocuments,
        ]);
    }
}
