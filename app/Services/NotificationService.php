<?php

namespace App\Services;
use App\Models\Notification;
use App\Services\FcmService;
use Illuminate\Support\Facades\Log;

class NotificationService
{
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

            if ($fcmToken && $this->firebaseAvailable()) {

                $fcmService = new FcmService();

                if (is_array($fcmToken)) {

                    foreach ($fcmToken as $token) {
                        if ($token) {
                            $fcmService->sendToToken($token, $title, $message, $data);
                        }
                    }

                } else {

                    $fcmService->sendToToken($fcmToken, $title, $message, $data);

                }
            }

            return $notification;

        } catch (\Throwable $e) {

            Log::error('NotificationService Error: '.$e->getMessage());

            return false;
        }
    }

    protected function firebaseAvailable(): bool
    {
        $path = storage_path('firebase/netisio-firebase-adminsdk-fbsvc-3c1792f39d.json');

        return file_exists($path) && is_readable($path);
    }
}
