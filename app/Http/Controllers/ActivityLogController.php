<?php

namespace App\Http\Controllers;

use App\Models\ActivityEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $authUser = $request->user();
        if (! $authUser->hasRole(\App\Support\RoleNames::ADMIN) && ! $authUser->hasRole('admin') && ! $authUser->can('activity_logs.view')) {
            abort(403, 'You are not authorized to view activity logs.');
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'activity_type' => ['nullable', 'string', 'max:100'],
            'module' => ['nullable', 'string', 'max:100'],
            'outcome' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $events = ActivityEvent::query()
            ->with('actorUser:id,first_name,last_name,username')
            ->when(isset($validated['activity_type']), fn ($query) => $query->where('activity_type', $validated['activity_type']))
            ->when(isset($validated['module']), fn ($query) => $query->where('module', $validated['module']))
            ->when(isset($validated['outcome']), fn ($query) => $query->where('outcome', $validated['outcome']))
            ->when(isset($validated['date_from']), fn ($query) => $query->whereDate('occurred_at', '>=', $validated['date_from']))
            ->when(isset($validated['date_to']), fn ($query) => $query->whereDate('occurred_at', '<=', $validated['date_to']))
            ->when(isset($validated['search']), function ($query) use ($validated) {
                $search = $validated['search'];

                $query->where(function ($q) use ($search) {
                    $q->where('activity_type', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%")
                        ->orWhere('module', 'LIKE', "%{$search}%")
                        ->orWhere('outcome', 'LIKE', "%{$search}%")
                        ->orWhere('resource_type', 'LIKE', "%{$search}%")
                        ->orWhere('resource_id', 'LIKE', "%{$search}%")
                        ->orWhereHas('actorUser', function ($actorQuery) use ($search) {
                            $actorQuery
                                ->where('first_name', 'LIKE', "%{$search}%")
                                ->orWhere('last_name', 'LIKE', "%{$search}%")
                                ->orWhere('username', 'LIKE', "%{$search}%");
                        });

                    if (str_contains(strtolower($search), 'system')) {
                        $q->orWhereNull('actor_user_id');
                    }
                });
            })
            ->latest('occurred_at')
            ->orderByDesc('id')
            ->paginate($this->clampedPageSize((int) ($validated['per_page'] ?? 25)));

        $pagination = [
            'current_page' => $events->currentPage(),
            'per_page' => $events->perPage(),
            'total' => $events->total(),
            'last_page' => $events->lastPage(),
        ];

        return response()->json([
            'data' => collect($events->items())->map(fn (ActivityEvent $event) => [
                'id' => $event->id,
                'activity_type' => $event->activity_type,
                'module' => $event->module,
                'description' => $event->description,
                'outcome' => $event->outcome,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'actor' => [
                    'id' => $event->actorUser?->id,
                    'display_name' => $event->actorUser?->full_name ?: $event->actorUser?->username ?: 'System',
                ],
                'resource_type' => $event->resource_type,
                'resource_id' => $event->resource_id,
            ])->values(),
            'pagination' => $pagination,
            'meta' => $pagination,
        ]);
    }

    private function clampedPageSize(int $requestedPageSize): int
    {
        return match (true) {
            $requestedPageSize <= 25 => 25,
            $requestedPageSize <= 50 => 50,
            default => 100,
        };
    }
}
