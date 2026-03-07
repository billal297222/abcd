<?php

namespace App\Console\Commands;

use App\Models\Kid;
use App\Models\WeeklyPayment;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateWeeklyPayments extends Command
{
    protected $signature = 'payments:generate';

    protected $description = 'Generate weekly payments for each kid';

    public function handle()
    {
        $now = Carbon::now();

        $kids = Kid::with('weeklyPayments')->get();

        foreach ($kids as $kid) {

            foreach ($kid->weeklyPayments as $bill) {

                if ($bill->status == 'unpaid' && $now->greaterThanOrEqualTo($bill->due_date)) {
                    $bill->update(['status' => 'expired']);
                    $this->info("Bill {$bill->id} expired");
                }
            }

            $types = ['Electricity', 'Internet'];

            foreach ($types as $type) {

                $lastBill = $kid->weeklyPayments()
                    ->where('type', $type)
                    ->latest()
                    ->first();

                if (! $lastBill) {
                    $amount = mt_rand(1000, 2000) / 100;

                    WeeklyPayment::create([
                        'kid_id' => $kid->id,
                        'parent_id' => $kid->parent_id,
                        'type' => $type,
                        'amount' => $amount,
                        'due_date' => Carbon::now()->addWeek(),
                        'status' => 'unpaid',
                    ]);

                    $this->info("First {$type} bill created for kid {$kid->id}");

                    continue;
                }

                if ($now->greaterThanOrEqualTo($lastBill->due_date)) {
                    $amount = mt_rand(1000, 2000) / 100;

                    WeeklyPayment::create([
                        'kid_id' => $kid->id,
                        'parent_id' => $kid->parent_id,
                        'type' => $type,
                        'amount' => $amount,
                        'due_date' => Carbon::now()->addWeek(),
                        'status' => 'unpaid',
                    ]);

                    $this->info("New {$type} bill created for kid {$kid->id}");
                }
            }
        }

        $this->info('Weekly payment generation completed.');
    }
}
