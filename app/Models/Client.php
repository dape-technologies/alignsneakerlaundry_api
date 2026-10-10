<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'phone', 'email', 'notes'];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function shoes()
    {
        return $this->hasMany(Shoe::class);
    }
}
