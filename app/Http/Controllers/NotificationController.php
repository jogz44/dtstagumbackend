<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Get paginated notifications for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Notification::query()
            ->where('user_id', $user->id)
            ->with(['document:id,tracking_number,document_type,status,due_date']);

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

        return response()->json([
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'unread_count' => Notification::where('user_id', $user->id)->unread()->count(),
        ]);
    }

    /**
     * Get count of unread notifications for the authenticated user.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = Notification::where('user_id', $request->user()->id)
            ->unread()
            ->count();

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
