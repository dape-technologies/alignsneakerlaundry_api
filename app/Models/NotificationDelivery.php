<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $fillable = [
        'cleaning_session_id', 'idempotency_key', 'event', 'channel', 'trigger',
        'destination', 'status', 'attempts', 'queued_at', 'sent_at', 'failed_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function cleaningSession()
    {
        return $this->belongsTo(CleaningSession::class);
    }
}
