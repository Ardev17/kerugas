<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notificações in-app (secção 23). Suporta leitura individual e
 * marcação em massa como lidas.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()->appNotifications()
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => NotificationResource::collection($notifications),
            'meta' => [
                'unread_count' => $request->user()->appNotifications()->whereNull('read_at')->count(),
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
            ],
        ]);
    }

    public function markRead(Request $request, AppNotification $notification): JsonResponse
    {
        abort_if($notification->user_id !== $request->user()->id, 403);

        $notification->update(['read_at' => now()]);

        return response()->json(['success' => true, 'data' => new NotificationResource($notification)]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->appNotifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Todas as notificações marcadas como lidas.']);
    }
}
