<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'kid_id',
        'receiver_type',
        'title',
        'message',
        'data',
        'is_read'
    ];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
    ];

    public function parents()
    {
        return $this->belongsTo(ParentModel::class, 'parent_id');
    }

    public function kids()
    {
        return $this->belongsTo(Kid::class, 'kid_id');
    }
}
