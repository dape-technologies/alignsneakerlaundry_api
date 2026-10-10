<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'shoe_type', 'brand', 'color', 'service', 'condition_notes', 'quantity', 'unit_price',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'integer'];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function photos()
    {
        return $this->hasMany(OrderPhoto::class);
    }
}
