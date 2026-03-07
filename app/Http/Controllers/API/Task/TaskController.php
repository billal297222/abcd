<?php

namespace App\Http\Controllers\API\Task;

use App\Http\Controllers\Controller;
use App\Models\Kid;
use App\Models\Task;
use App\Services\NotificationService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    use ApiResponse;

    public function createTask(Request $request)
    {
        $parent = auth('parent')->user();
        $request->validate([
            'kid_id' => 'required|exists:kids,id',
            'title' => 'required|string|max:150',
            'description' => 'nullable|string|max:200',
            'reward_amount' => 'required|numeric|min:0',
        ]);

        $kid = Kid::where('id', $request->kid_id)->where('parent_id', $parent->id)->first();
        if (! $kid) {

            return $this->error('', 'kids not found', 404);
        }

        $task = Task::create([
            'kid_id' => $request->kid_id,
            'title' => $request->title,
            'description' => $request->description,
            'reward_amount' => $request->reward_amount,
            'status' => 'not_started',
            'due_date' => Carbon::today(),
            'created_by_parent_id' => $parent->id,
        ]);

        $taskData = [
            'id' => $task->id,
            'kid_id' => $kid->id,
            'kid_name' => $kid->full_name,
            'kid_avatar' => $kid->kavatar ? url($kid->kavatar) : null, // added avatar path
            'title' => $task->title,
            'description' => $task->description,
            'reward_amount' => $task->reward_amount,
            'status' => $task->status,
            'due_date' => $task->due_date,
        ];

        return $this->success($taskData, 'Task created successfully', 201);
    }

    public function startTask($taskId)
    {
        $kid = auth('kid')->user();
        $task = Task::where('id', $taskId)->where('kid_id', $kid->id)->first();

        if (! $task) {

            return $this->error('', 'Task not found', 404);
        }

        if ($task->status !== 'not_started') {

            return $this->error('', 'Task already started', 400);

        }
        $task->update([
            'status' => 'in_progress',
        ]);

        return $this->success('', 'Task started successfully!', 200);
    }

    public function completeTask($taskId)
    {
        $kid = auth('kid')->user();
        $task = Task::where('id', $taskId)->where('kid_id', $kid->id)->first();

        if (! $task) {

            return $this->error('', 'Task not found', 404);
        }

        if ($task->status !== 'in_progress') {

            return $this->error('', 'You have to start the task', 400);

        }
        $task->update([
            'status' => 'completed',
        ]);

        // .......................send notification to parrent and kids...............

        try {
            $notificationService = app(NotificationService::class);

            // Send notification to the kid
            if ($kid) {
                $notificationService->send(
                    parentId: null,
                    kidId: $kid->id,
                    receiverType: 'kid',
                    title: 'Task Completed',
                    message: 'You earned '.number_format($task->reward_amount, 2).' for the task "'.$task->title.'"',
                    data: ['task_id' => $task->id,
                        'reward_amount' => $task->reward_amount,
                        'kid_avatar' => $kid->kavatar ? url($kid->kavatar) : null,
                    ],
                    fcmToken: $kid->fcm_token
                );
            }

            // Send notification to the parent
            if ($kid->parent) {
                $notificationService->send(
                    parentId: $kid->parent->id,
                    kidId: $kid->id,
                    receiverType: 'parent',
                    title: 'Task Completed',
                    message: $kid->full_name.' completed a task! Earned: '.number_format($task->reward_amount, 2).' for "'.$task->title.'"',
                    data: ['task_id' => $task->id,
                        'reward_amount' => $task->reward_amount,
                        'kid_avatar' => $kid->kavatar ? url($kid->kavatar) : null,
                    ],
                    fcmToken: $kid->parent->fcm_token
                );
            }

        } catch (\Throwable $e) {
            \Log::error('NotificationService Error: '.$e->getMessage());
        }

        // ---------------------------

        return $this->success('', 'Task completed successfully!', 200);
    }

    public function rewardCollected($taskId)
    {
        $kid = auth('kid')->user();
        $task = Task::where('id', $taskId)->where('kid_id', $kid->id)->first();

        if (! $task) {
            return $this->error('', 'Task not found', 404);
        }

        if ($task->status !== 'completed') {

            return $this->error('', 'You have to complete the task', 400);

        }

        $task->update([
            'status' => 'reward_collected',
        ]);

        $kid->balance += $task->reward_amount;
        $kid->save();

        $data = [
            'task' => $task,
            'new_balance' => $kid->balance,
            'kid_avatar' => $kid->kavatar ? url($kid->kavatar) : null,
        ];

        return $this->success($data, 'Reward collected successfully!', 200);
    }

    public function getKidTasks()
    {
        $kid = auth('kid')->user();
        $tasks = Task::where('kid_id', $kid->id)->orderBy('created_at', 'desc')->get()
            ->map(function ($task) use ($kid) {
                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'reward_amount' => $task->reward_amount,
                    'status' => $task->status,
                    'due_date' => $task->due_date,
                    'kid_avatar' => $kid->kavatar ? url($kid->kavatar) : null,
                ];
            });

        return $this->success($tasks, 'Tasks retrieved successfully.', 200);
    }
}
