<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;


class WeeklyPayment extends Model
{
    protected $fillable = ['kid_id','parent_id','type','amount','due_date','status'];

    protected $appends = ['due_in_days'];

    public function kid()
    {
        return $this->belongsTo(Kid::class);
    }

    public function parent()
    {
        return $this->belongsTo(ParentModel::class);
    }


    public function getDueInDaysAttribute()
    {
        $today = Carbon::today();
        $due = Carbon::parse($this->due_date);
        $diff = $today->diffInDays($due, false);
        return $diff >= 0 ? $diff : 0;
    }

    public function updateStatus()
    {
        $today = Carbon::today();

        if ($this->status !== 'paid' && $today->gt(Carbon::parse($this->due_date))) {
            $this->update(['status' => 'expired']);
        }
    }

    // For testing only, using minutes instead of days
        public function nextDueTime($minutes = 2)
        {
            return Carbon::parse($this->due_date)->addMinutes($minutes);
        }
}

