<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderStatusHistory extends Model
{
    protected $table = 'order_status_history';

    protected $fillable = ['from_status', 'to_status', 'changed_by', 'notes'];

    public function changer()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
