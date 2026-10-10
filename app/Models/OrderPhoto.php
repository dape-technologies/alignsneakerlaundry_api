<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class OrderPhoto extends Model
{
    protected $fillable = ['order_id', 'order_item_id', 'cleaning_session_id', 'path', 'type'];

    protected $appends = ['url'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function item()
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function cleaningSession()
    {
        return $this->belongsTo(CleaningSession::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}
