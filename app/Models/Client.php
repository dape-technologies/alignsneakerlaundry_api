<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class Client extends Model
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'phone', 'email', 'notes'];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function routeNotificationForMail(): ?string
    {
        return $this->email;
    }
}
