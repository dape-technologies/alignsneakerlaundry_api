<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $fillable = ['number'];

    protected $appends = ['label'];

    protected function casts(): array
    {
        return ['number' => 'integer'];
    }

    public function cleaningSessions()
    {
        return $this->hasMany(CleaningSession::class);
    }

    public function getLabelAttribute(): string
    {
        return 'Tag '.str_pad((string) $this->number, 2, '0', STR_PAD_LEFT);
    }
}
