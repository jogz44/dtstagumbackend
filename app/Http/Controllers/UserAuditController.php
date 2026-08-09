<?php

namespace App\Http\Controllers;

use App\Models\AccountHistoryEvent;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserAuditController extends Controller
{
    public function history(Request $request, User $user): JsonResponse
    {
        $this->authorizeAuditRead($request->user(), $user);

        $events = AccountHistoryEvent::query()
            ->with('actorUser:id,first_name,last_name,username')
            ->where('subject_user_id', $user->id)
            ->when($request->filled('event_type'), fn ($query) => $query->where('event_type', $request->string('event_type')))
            ->latest('occurred_at')
            ->paginate($request->integer('per_page', 10));

        return response()->json([
            'data' => collect($events->items())->map(fn (AccountHistoryEvent $event) => [
                'id' => $event->id,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'changed_by' => $event->actorUser?->full_name ?: $event->actorUser?->username ?: 'System',
                'event_type' => $event->event_type,
                'summary' => $event->summary,
                'changes' => $event->changes ?? [],
            ])->values(),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
        ]);
    }

    public function activity(Request $request, User $user): JsonResponse
    {
        $this->authorizeAuditRead($request->user(), $user);

        $events = ActivityEvent::query()
            ->where('actor_user_id', $user->id)
            ->when($request->filled('activity_type'), fn ($query) => $query->where('activity_type', $request->string('activity_type')))
            ->when($request->filled('module'), fn ($query) => $query->where('module', $request->string('module')))
            ->latest('occurred_at')
            ->paginate($request->integer('per_page', 10));

        return response()->json([
            'data' => collect($events->items())->map(fn (ActivityEvent $event) => [
                'id' => $event->id,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'activity_type' => $event->activity_type,
                'activity' => $event->description,
                'module' => $event->module,
                'resource_type' => $event->resource_type,
                'resource_id' => $event->resource_id,
                'outcome' => $event->outcome,
                'ip_address' => $event->ip_address,
                'device' => $this->deviceLabel($event->user_agent),
                'location' => 'Tagum City, PH',
            ])->values(),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
        ]);
    }

    private function authorizeAuditRead(User $authUser, User $targetUser): void
    {
        if ($authUser->hasRole('super_admin')) {
            return;
        }

        if (
            $authUser->hasRole('supervisor')
            && $authUser->office_id === $targetUser->office_id
            && $authUser->division_id === $targetUser->division_id
        ) {
            return;
        }

        abort(403, 'You are not authorized to view this user audit data.');
    }

    private function deviceLabel(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'Unknown device';
        }

        $browser = str_contains($userAgent, 'Chrome') ? 'Chrome' : (str_contains($userAgent, 'Firefox') ? 'Firefox' : 'Browser');
        $os = str_contains($userAgent, 'Windows') ? 'Windows' : (str_contains($userAgent, 'Mac') ? 'macOS' : 'Device');

        return "{$browser} on {$os}";
    }
}
