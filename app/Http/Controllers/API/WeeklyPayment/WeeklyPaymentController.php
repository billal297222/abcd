<?php

namespace App\Http\Controllers\API\WeeklyPayment;

use App\Http\Controllers\Controller;
use App\Models\Kid;
use App\Models\WeeklyPayment;
use App\Services\FcmService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;

class WeeklyPaymentController extends Controller
{
    use ApiResponse;

    // public function createWeeklyPayment(Request $request)
    // {
    //     $parent = auth('parent')->user();
    //     $request->validate([
    //         'kid_id' => 'required|exists:kids,id',
    //         'title' => 'required|string|max:150',
    //         'amount' => 'required|numeric|min:0',
    //         'icon' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg',
    //     ]);

    //     $kid = Kid::where('id', $request->kid_id)
    //         ->where('parent_id', $parent->id)
    //         ->first();

    //     if (! $kid) {
    //         return $this->error('', 'Kid not Found', 404);
    //     }

    //     if ($request->hasFile('icon')) {
    //         $file = $request->file('icon');
    //         $filename = time().'_'.$file->getClientOriginalName();
    //         $file->move(public_path('uploads/weekly_payments/'), $filename);
    //         $request->icon = url('uploads/weekly_payments/'.$filename);
    //     }

    //     $lastDate = Carbon::now()->addDays(7);
    //     $dueDate = Carbon::now()->diffInDays($lastDate, false);
    //     $dueDate = $dueDate + 1;
    //     $weeklyPayment = WeeklyPayment::create([
    //         'kid_id' => $request->kid_id,
    //         'title' => $request->title,
    //         'amount' => $request->amount,
    //         'icon' => $request->icon,
    //         'last_date' => $lastDate->toDateString(),
    //         'due_in_days' => $dueDate,
    //         'status' => 'pending',
    //         'created_by_parent_id' => $parent->id,
    //     ]);

    //     $data = [
    //         'weekly_payment' => $weeklyPayment,
    //         'kid_avatar' => $kid->kavatar ? url($kid->kavatar) : null,
    //         'due_days' => $dueDate,
    //     ];

    //     return $this->success($data, 'Weekly payment created successfully!', 201);
    // }

    public function payWeeklyPayment($id)
    {
        $kid = auth('kid')->user();
        $payment = WeeklyPayment::where('id', $id)->where('kid_id', $kid->id)->first();

        if (! $payment) {
            return $this->error('', 'Weekly payment not found', 404);
        }

        $dueInDays = Carbon::now()->diffInDays(Carbon::parse($payment->last_date), false);
        $dueInDays = $dueInDays + 1;

        if ($dueInDays < 0 && $payment->status !== 'paid') {
            $payment->status = 'late';
            $payment->save();
        }

        if ($payment->status === 'paid') {
            return $this->error('', 'This weekly payment is already paid.', 400);
        }

        if ($payment->status === 'late') {
            return $this->error('', 'This weekly payment is overdue and cannot be paid.', 400);
        }

        if ($kid->balance < $payment->amount) {
            return $this->error('', 'Not enough balance to pay this weekly payment.', 400);
        }

        $kid->balance -= $payment->amount;
        $kid->save();
        $payment->update([
            'status' => 'paid',
        ]);

        // Use FcmService-------------------------------------
        try {
            $fcmService = new FcmService;

            // Send to the kid
            if ($kid && $kid->fcm_token) {
                $fcmService->sendToToken(
                    $kid->fcm_token,
                    'Weekly Payment Completed!',
                    'Paid '.number_format($payment->amount, 2).' for the payment "'.$payment->title.'"'
                );
            }

            // Send to the parent
            if ($kid->parent && $kid->parent->fcm_token) {
                $fcmService->sendToToken(
                    $kid->parent->fcm_token,
                    $kid->full_name.' completed a weekly payment!',
                    'Paid: '.number_format($payment->amount, 2).' for "'.$payment->title.'"'
                );
            }
        } catch (\Exception $e) {
            \Log::error('FCM Error: '.$e->getMessage());
        }
        // ---------------------------

        $data = [
            'weekly_payment' => $payment,
            'kid_balance' => $kid->balance,
            'kid_avatar' => $kid->kavatar ? url($kid->kavatar) : null, // added avatar path
        ];

        return $this->success($data, 'Weekly payment successfully paid!', 200);
    }

    public function getKidPayment()
    {
        $kid = auth('kid')->user();

        $payments = WeeklyPayment::where('kid_id', $kid->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($p) use ($kid) {

                $dueInDays = Carbon::now()->diffInDays(Carbon::parse($p->last_date), false);

                $dueInDays = $dueInDays + 1;
                if ($dueInDays < 0 && $p->status !== 'paid') {
                    $p->status = 'expired';
                    $p->save();
                }

                return [
                    'id' => $p->id,
                    'title' => $p->title,
                    'icon' => $p->icon ? url($p->icon) : null,
                    'amount' => $p->amount,
                    'due_in_days' => $dueInDays,
                    'status' => $p->status,
                    'kid_avatar' => $kid->kavatar ? url($kid->kavatar) : null,
                ];
            });

        return $this->success($payments, 'Weekly payment retrieved successfully.', 200);
    }

    public function requestMoneyPayment(Request $request, $payment_id)
    {
        $kid = auth('kid')->user();

        $payment = WeeklyPayment::where('id', $payment_id)
            ->where('kid_id', $kid->id)
            ->first();

        if (! $payment) {
            return $this->error('', 'Weekly payment not found for this kid.', 404);
        }

        $need = $payment->amount - $kid->balance;

        $parent = $kid->parent;

        if ($parent && $parent->fcm_token) {
            try {
                $fcmService = new FcmService;
                $fcmService->sendToToken(
                    $parent->fcm_token,
                    $kid->full_name.' is out of money!',
                    'Needs '.number_format($need, 2).' to pay "'.$payment->title.'"'
                );
            } catch (\Exception $e) {
                \Log::error('FCM Error: '.$e->getMessage());
            }
        }

        $data = [
            'need_amount' => $need,
            'payment_id' => $payment->id,
        ];

        return $this->success($data, 'Money request sent to parent successfully.', 200);

    }

    public function kidsBill()
    {
        $kid = auth('kid')->user();
        $bills = $kid->weeklyPayments()
            ->orderBy('due_date', 'desc')
            ->get(['id', 'type', 'amount', 'due_date', 'status']);

        $dueDays = $bills->map(function ($bill) {
            // $dueInDays = Carbon::now()->diffInDays(Carbon::parse($bill->due_date), false)+1;
            // return $dueInDays >= 0 ? $dueInDays : 0;
             $dueInDays = Carbon::parse($bill->due_date)->isPast() ? 0 :
                         Carbon::now()->diffInDays(Carbon::parse($bill->due_date)) + 1;
        });

        return response()->json([
            'kid_id' => $kid->id,
            'parent_id' => $kid->parent_id,
            'bills' => $bills,
            'due_days' => $dueDays,
        ]);
    }
}
