<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CleaningSession extends Model
{
    use HasFactory;

    public const STATUSES = [
        'received', 'cleaning', 'drying', 'quality_check',
        'ready_for_collection', 'collected', 'cancelled',
    ];

    public const TRANSITIONS = [
        'received' => ['cleaning', 'cancelled'],
        'cleaning' => ['drying', 'cancelled'],
        'drying' => ['quality_check', 'cleaning', 'cancelled'],
        'quality_check' => ['ready_for_collection', 'cleaning', 'cancelled'],
        'ready_for_collection' => ['collected', 'cleaning'],
        'collected' => [],
        'cancelled' => [],
    ];

    protected $fillable = [
        'order_id', 'shoe_id', 'status', 'service', 'condition_notes', 'unit_price',
        'ready_cycle', 'received_at', 'cleaning_at', 'drying_at',
        'quality_checked_at', 'ready_at', 'collected_at',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'ready_cycle' => 'integer',
            'received_at' => 'datetime',
            'cleaning_at' => 'datetime',
            'drying_at' => 'datetime',
            'quality_checked_at' => 'datetime',
            'ready_at' => 'datetime',
            'collected_at' => 'datetime',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function shoe()
    {
        return $this->belongsTo(Shoe::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(CleaningSessionStatusHistory::class)->latest();
    }

    public function notifications()
    {
        return $this->hasMany(NotificationDelivery::class)->latest();
    }

    public function photos()
    {
        return $this->hasMany(OrderPhoto::class);
    }
}
