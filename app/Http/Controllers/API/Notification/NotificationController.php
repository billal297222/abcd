<?php

namespace App\Http\Controllers\API\Notification;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use ApiResponse;

    public function getNotifications(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'user_type' => 'required |in:kid,parent',
        ]);

        $notificaitons = Notification::where('receiver_type', $request->user_type)
            ->where(function ($query) use ($request) {
                if ($request->user_type === 'kid') {
                    $query->where('kid_id', $request->user_id);
                } elseif ($request->user_type === 'parent') {
                    $query->where('parent_id', $request->user_id);
                }
            })
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->success($notificaitons, 'User ntifications', 200);
    }

    public function markAsRead($notification_id)
    {
        $notification = Notification::find($notification_id);

        if (! $notification) {
            return $this->error('', 'Notification not found', 404);
        }

        $notification->is_read = true;
        $notification->save();

        return $this->success( $notification, 'Notification marked as read', 200);
    }
}
