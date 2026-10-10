<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    public const STATUSES = [
        'received',
        'washing',
        'drying',
        'quality_check',
        'ready',
        'collected',
        'cancelled',
    ];

    protected $fillable = [
        'reference', 'client_id', 'created_by', 'status', 'received_at', 'due_at',
        'ready_at', 'collected_at', 'subtotal', 'discount', 'total', 'notes',
        'ready_notified_at',
    ];

    protected $appends = ['amount_paid', 'balance'];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'due_at' => 'datetime',
            'ready_at' => 'datetime',
            'collected_at' => 'datetime',
            'ready_notified_at' => 'datetime',
            'subtotal' => 'integer',
            'discount' => 'integer',
            'total' => 'integer',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function photos()
    {
        return $this->hasMany(OrderPhoto::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function statusHistory()
    {
        return $this->hasMany(OrderStatusHistory::class)->latest();
    }

    public function getAmountPaidAttribute(): int
    {
        return (int) ($this->relationLoaded('payments')
            ? $this->payments->sum('amount')
            : $this->payments()->sum('amount'));
    }

    public function getBalanceAttribute(): int
    {
        return max(0, $this->total - $this->amount_paid);
    }
}
