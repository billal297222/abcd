<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Backend;
use App\Models\ParentModel;
use App\Models\Kid;
use App\Models\KidTransaction;
use App\Models\ParentTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ResetMonthlyLimit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:reset-monthly-limit';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
{
    $currentMonth = now()->format('Y-m');

    $parents = ParentModel::all();
    $backend = Backend::first();
    foreach ($parents as $parent) {

        $lastTransaction = ParentTransaction::where('parent_id', $parent->id)
            ->where('type', 'deposit')
            ->latest('transaction_datetime')
            ->first();

        if (!$lastTransaction ||  \Carbon\Carbon::parse($lastTransaction->transaction_datetime)->format('Y-m') != $currentMonth) {

            $parent->available_limit =$backend->monthly_limit;
            $parent->save();
        }
    }

    $this->info('Monthly limit reset completed.');
}
}
