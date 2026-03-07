<?php

namespace App\Http\Controllers\API\SavingGoals;

use App\Http\Controllers\Controller;
use App\Models\Kid;
use App\Models\KidTransaction;
use App\Models\Saving;
use App\Services\NotificationService;
use App\Models\Notification;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class SavingGoalController extends Controller
{
    use ApiResponse;



    public function createGoal(Request $request)
    {
        $request->validate([
            'kid_id' => 'nullable|exists:kids,id',
            'title' => 'required|string|max:150',
            'description' => 'nullable|string|max:200',
            'target_amount' => 'required|numeric|min:0.01',
        ]);

        $kid = null;
        $createdByParentId = null;

        if (auth('kid')->check()) {
            $kid = auth('kid')->user();

        } elseif (auth('parent')->check()) {
            $parent = auth('parent')->user();

            if (! $request->kid_id) {

                return $this->error('', 'kid_id is required when parent creates a goal.', 422);
            }

            $kid = Kid::where('id', $request->kid_id)
                ->where('parent_id', $parent->id)
                ->first();

            if (! $kid) {

                return $this->error('', 'Invalid kid or kid not associated with this parent.', 403);
            }

            $createdByParentId = $parent->id;
        } else {

            return $this->error('', 'Unauthorized user.', 401);
        }

        $goal = Saving::create([
            'kid_id' => $kid->id,
            'title' => $request->title,
            'description' => $request->description ?? '',
            'target_amount' => $request->target_amount,
            'saved_amount' => 0.00,
            'status' => 'in_progress',
            'created_by_parent_id' => $createdByParentId,
        ]);

        $goalData = [
            'id' => $goal->id,
            'kid_id' => $kid->id,
            'kid_name' => $kid->username,
            'kid_avatar' => $kid->kavatar ? url($kid->kavatar) : null,
            'title' => $goal->title,
            'description' => $goal->description,
            'target_amount' => $goal->target_amount,
            'saved_amount' => $goal->saved_amount,
            'status' => $goal->status,
        ];

        //................Notification when goal created ..............

        try {
            $notificationService = app(NotificationService::class);

            if (is_null($createdByParentId) && $kid->parent) {

                $notificationService->send(
                    parentId: $kid->parent->id,
                    kidId: $kid->id,
                    receiverType: 'parent',
                    title: 'Goal Created',
                    message: $kid->username.' created a new goal: "'.$goal->title.'"',
                    data: [
                        'goal_id' => $goal->id,
                        'goal_title' => $goal->title,
                        'kid_avatar' => $kid->kavatar ? url($kid->kavatar) : null,
                    ],
                    fcmToken: $kid->parent->fcm_token
                );
            }

        } catch (\Throwable $e) {
            \Log::error('NotificationService Error: '.$e->getMessage());
        }

        return $this->success($goalData, 'Saving goal created successfully.', 201);
    }

    // AddMoneyToGoal
    public function AddMoneyToGoal(Request $request, $goal_id)
    {
        $kid = auth('kid')->user();

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        $goal = Saving::where('id', $goal_id)->where('kid_id', $kid->id)->first();
        if (! $goal) {

            return $this->error('', 'Goals not found.', 401);
        }

        // ...............Notification when goal completed ..............

        if ($goal->status === 'completed') {
            try {
                $notificationService = app(NotificationService::class);

                if ($kid) {
                    $notificationService->send(
                        parentId: null,
                        kidId: $kid->id,
                        receiverType: 'kid',
                        title: 'Saving Goal Completed',
                        message: 'You completed the goal "'.$goal->title.'"',
                        data: ['goal_id' => $goal->id],
                        fcmToken: $kid->fcm_token
                    );
                }

                if ($kid->parent) {
                    $notificationService->send(
                        parentId: $kid->parent->id,
                        kidId: $kid->id,
                        receiverType: 'parent',
                        title: 'Saving Goal Completed',
                        message: $kid->full_name.' completed a goal: "'.$goal->title.'"',
                        data: ['goal_id' => $goal->id],
                        fcmToken: $kid->parent->fcm_token
                    );
                }

            } catch (\Throwable $e) {
                \Log::error('NotificationService Error: '.$e->getMessage());
            }

            // ---------------------------
            return $this->error('', ' Goals already completed', 400);
        }

        $newAmount = $goal->saved_amount + $request->amount;
        if ($newAmount > $goal->target_amount) {

            $exceedAmount = number_format($goal->target_amount - $goal->saved_amount, 2);

            return $this->error($exceedAmount, 'Amount exceeds target goal. You can add up to', 400);
        }

        $goal->saved_amount = $newAmount;

        if ($newAmount >= $goal->target_amount) {
            $goal->status = 'completed';
        }
        $goal->save();

        $progress = round(($goal->saved_amount / $goal->target_amount) * 100, 2);

        KidTransaction::create([
            'kid_id' => $kid->id,
            'type' => 'saving',
            'saving_goal_id' => $goal->id,
            'amount' => $request->amount,
            'status' => 'completed',
            'transaction_date' => now(),
            'note' => 'Added to saving goal: '.$goal->title,
            'progress_percentage' => $progress,
        ]);

        $data = [
            'id' => $goal->id,
            'title' => $goal->title,
            'saved_amount' => number_format($goal->saved_amount, 2),
            'target_amount' => number_format($goal->target_amount, 2),
            'status' => $goal->status,
            'progress_percentage' => $progress,
            'kid_avatar' => $kid->kavatar ? url($kid->kavatar) : null, // fixed path
        ];

        return $this->success($data, 'Amount added successfully', 200);
    }

    // collectGoal
    public function collectGoal(Request $request, $goal_id)
    {
        $kid = auth('kid')->user();

        $request->validate([
            'action' => 'required|in:yes,cancel',
        ]);

        $goal = Saving::where('id', $goal_id)->where('kid_id', $kid->id)->firstOrFail();

        if ($goal->status === 'collected') {

            return $this->error('', 'Goal reward already collected.', 400);
        }

        if ($goal->status !== 'completed') {

            return $this->error('', 'Goal is not completed yet.', 400);
        }

        if ($request->action === 'yes') {

            $goal->status = 'collected';
            $goal->save();

            return $this->success($goal->title, 'Goal collected successfully', 200);

        } elseif ($request->action === 'cancel') {
            $kid->balance += $goal->saved_amount;
            $kid->save();
            $goal->status = 'collected';
            $goal->save();

            KidTransaction::create([
                'kid_id' => $kid->id,
                'type' => 'refund',
                'saving_goal_id' => $goal->id,
                'amount' => $goal->saved_amount,
                'status' => 'completed',
                'note' => 'Goal cancelled, amount refunded: '.$goal->title,
                'transaction_date' => now(),
            ]);

            $data = [
                'goal' => $goal,
                'balance' => number_format($kid->balance, 2),
                'kid_avatar' => $kid->kavatar ? url($kid->kavatar) : null, // fixed path
            ];

            return $this->success($data, 'You clicked cancel. Amount returned to your balance.', 200);
        }

        return $this->error('', 'Invalid action', 400);
    }


    public function getNotifications(Request $request){
        $request->validate([
            'user_id' =>'required|integer',
            'user_type' => 'required |in:kid,parent',
        ]);

        $notificaitons = Notification::where('receiver_type',$request->user_type)
            ->where(function($query) use ($request){
                if($request->user_type === 'kid'){
                    $query->where('kid_id',$request->user_id);
                }else if($request->user_type === 'parent'){
                    $query->where('parent_id',$request->user_id);
                }
            })
            ->orderBy('created_at','desc')
            ->get();

            return $this->success($notificaitons,'User ntifications',200);

    }
}
