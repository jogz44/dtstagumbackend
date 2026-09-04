<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Allowed notification types per policy:
     * 1. Force notification
     * 2. Document transmitted / received
     * 3. Document due tomorrow
     */
    protected function scopeAllowedNotifications($query)
    {
        $allowedTypes = ['document.force_notify', 'document.received', 'document.due_tomorrow', 'document.due_soon'];

        return $query->whereIn('type', $allowedTypes)
            ->where(function ($q) {
                $q->whereIn('type', ['document.force_notify', 'document.received', 'document.due_tomorrow'])
                  ->orWhere(function ($sub) {
                      $sub->where('type', 'document.due_soon')
                          ->where(function ($inner) {
                              $inner->where('title', 'like', '%tomorrow%')
                                    ->orWhere('message', 'like', '%tomorrow%');
                          });
                  });
            });
    }

    /**
     * Get paginated notifications for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Notification::query()
            ->where('user_id', $user->id);

        $this->scopeAllowedNotifications($query);

        $query->with(['document:id,tracking_number,document_type,status,due_date']);

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        $readStatus = $request->query('read_status');
        if ($readStatus === 'unread') {
            $query->whereNull('read_at');
        } elseif ($readStatus === 'read') {
            $query->whereNotNull('read_at');
        }

        $perPage = max(1, min((int) $request->query('per_page', 10), 50));
        $paginator = $query->latest('created_at')->paginate($perPage);

        $unreadCountQuery = Notification::where('user_id', $user->id);
        $this->scopeAllowedNotifications($unreadCountQuery);
        $unreadCount = $unreadCountQuery->unread()->count();

        return response()->json([
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Get count of unread notifications for the authenticated user.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $query = Notification::where('user_id', $request->user()->id);
        $this->scopeAllowedNotifications($query);
        $count = $query->unread()->count();

        return response()->json([
            'unread_count' => $count,
        ]);
    }

    /**
     * Mark a specific notification as read for the authenticated user.
     */
    public function markAsRead(Request $request, Notification $notification): JsonResponse
    {
        if ($notification->user_id !== $request->user()->id) {
            abort(404, 'Notification not found.');
        }

        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        $unreadCount = Notification::where('user_id', $request->user()->id)
            ->unread()
            ->count();

        return response()->json([
            'message' => 'Notification marked as read.',
            'notification' => $notification,
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Mark all notifications as read for the authenticated user.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        Notification::where('user_id', $request->user()->id)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'All notifications marked as read.',
            'unread_count' => 0,
        ]);
    }
}
