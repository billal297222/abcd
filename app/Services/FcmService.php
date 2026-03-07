<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FcmService
{
    protected $messaging;

    public function __construct()
    {
        $factory = (new Factory)
            ->withServiceAccount(storage_path('firebase/netisio-firebase-adminsdk-fbsvc-3c1792f39d.json'));

        $this->messaging = $factory->createMessaging();
    }

    public function sendToToken($token, $title, $body, $data = [])
    {
        try {

            $data = array_map('strval', $data);

            $message = CloudMessage::withTarget('token', $token)
                ->withNotification(Notification::create($title, $body))
                ->withData($data);

            return $this->messaging->send($message);

        } catch (\Throwable $e) {

            \Log::error('FCM Send Error: '.$e->getMessage());

            return false;
        }
    }
}
