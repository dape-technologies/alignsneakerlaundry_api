<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Shoe extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference', 'client_id', 'shoe_type', 'brand', 'model', 'color', 'size',
        'identifying_notes', 'reference_photo_path', 'active',
    ];

    protected $appends = ['display_name', 'reference_photo_url'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function cleaningSessions()
    {
        return $this->hasMany(CleaningSession::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return trim(collect([$this->color, $this->brand, $this->model, $this->shoe_type])->filter()->unique()->join(' '));
    }

    public function getReferencePhotoUrlAttribute(): ?string
    {
        return $this->reference_photo_path ? Storage::disk('public')->url($this->reference_photo_path) : null;
    }
}
