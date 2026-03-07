<?php

namespace App\Services;

use App\Models\Notification;
use App\Services\FcmService;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    protected $fcm;

    public function __construct(FcmService $fcm)
    {
        $this->fcm = $fcm;
    }

    public function send(
        ?int $parentId,
        ?int $kidId,
        string $receiverType,
        string $title,
        string $message,
        array $data = [],
        $fcmToken = null
    ) {
        try {
            $notification = Notification::create([
                'parent_id' => $parentId,
                'kid_id' => $kidId,
                'receiver_type' => $receiverType,
                'title' => $title,
                'message' => $message,
                'data' => $data,
            ]);

            if ($fcmToken) {
                if (is_array($fcmToken)) {
                    foreach ($fcmToken as $token) {
                        $this->fcm->sendToToken($token, $title, $message, $data);
                    }
                } else {
                    $this->fcm->sendToToken($fcmToken, $title, $message, $data);
                }
            }

            return $notification;
        } catch (\Throwable $e) {
            Log::error("NotificationService Error: " . $e->getMessage());
            return false;
        }
    }
}
