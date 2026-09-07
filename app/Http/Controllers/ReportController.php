<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    public function __construct(
        protected ReportService $reportService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'from',
            'to',
            'office_id',
            'division_id',
            'priority',
            'status',
            'search',
        ]);

        try {
            $report = $this->reportService->getDashboardReport($filters, $request->user());

            // Backwards compatibility mappings for legacy UI components if accessed directly
            $legacyKpiCards = [
                [
                    'label' => 'Total Documents',
                    'value' => (string) ($report['kpis']['total_documents']['value'] ?? 0),
                    'note' => $report['kpis']['total_documents']['comparison_label'] ?? 'System wide registered documents',
                    'indicator' => 'bg-green',
                ],
                [
                    'label' => 'Active Users',
                    'value' => (string) ($report['kpis']['active_users']['value'] ?? 0),
                    'note' => $report['kpis']['active_users']['comparison_label'] ?? 'Across active offices and divisions',
                    'indicator' => 'bg-blue',
                ],
                [
                    'label' => 'Total Offices',
                    'value' => (string) ($report['kpis']['total_offices']['value'] ?? 0),
                    'note' => 'Active organizational offices',
                    'indicator' => 'bg-amber',
                ],
                [
                    'label' => 'Total Divisions',
                    'value' => (string) ($report['kpis']['total_divisions']['value'] ?? 0),
                    'note' => 'Active operational divisions',
                    'indicator' => 'bg-purple',
                ],
            ];

            $legacyBreakdownPanels = [
                [
                    'key' => 'document-status',
                    'category' => 'Documents',
                    'title' => 'Status Breakdown',
                    'total' => $report['meta']['reconciliation']['documents'] ?? 0,
                    'totalLabel' => 'Total Docs',
                    'items' => array_map(function ($item) {
                        return [
                            'label' => $item['status'],
                            'value' => $item['count'],
                            'percent' => $item['percentage'] . '%',
                            'colorHex' => $item['color'],
                        ];
                    }, $report['status_breakdown'] ?? []),
                ],
            ];

            $responsePayload = array_merge($report, [
                'kpiCards' => $legacyKpiCards,
                'breakdownPanels' => $legacyBreakdownPanels,
                'divisionBlocks' => array_map(function ($item) {
                    return [
                        'title' => $item['division_name'],
                        'total' => $item['count'],
                        'color' => 'bg-green',
                        'items' => [
                            ['label' => 'Documents', 'value' => $item['count']],
                        ],
                    ];
                }, array_slice($report['documents_by_division']['items'] ?? [], 0, 6)),
                'documents' => array_map(function ($doc) {
                    return [
                        'id' => $doc['id'],
                        'trackingId' => $doc['tracking_id'],
                        'title' => $doc['title'],
                        'division' => $doc['division'],
                        'priority' => $doc['priority'],
                        'status' => $doc['status'],
                        'received' => $doc['sent_date'],
                    ];
                }, $report['documents_sent']['data'] ?? []),
            ]);

            return response()->json($responsePayload);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Unable to load report data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function offices(Request $request): JsonResponse
    {
        $filters = $request->only([
            'from',
            'to',
            'office_id',
            'division_id',
            'priority',
            'status',
            'search',
            'sort',
            'direction',
            'page',
            'per_page',
        ]);

        try {
            $data = $this->reportService->getOfficesReport($filters, $request->user());
            return response()->json($data);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Unable to load offices report data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function divisions(Request $request): JsonResponse
    {
        $filters = $request->only([
            'from',
            'to',
            'office_id',
            'division_id',
            'priority',
            'status',
            'search',
            'sort',
            'direction',
            'page',
            'per_page',
        ]);

        try {
            $data = $this->reportService->getDivisionsReport($filters, $request->user());
            return response()->json($data);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Unable to load divisions report data.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
