<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CleaningSessionStatusHistory extends Model
{
    protected $table = 'cleaning_session_status_history';

    protected $fillable = ['from_status', 'to_status', 'changed_by', 'notes'];

    public function cleaningSession()
    {
        return $this->belongsTo(CleaningSession::class);
    }

    public function changer()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
