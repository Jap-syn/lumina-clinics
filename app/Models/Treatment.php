<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Treatment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'requires_consent' => 'boolean',
            'active' => 'boolean',
            'duration_minutes' => 'integer',
        ];
    }

    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class);
    }

    public function therapists(): BelongsToMany
    {
        return $this->belongsToMany(Therapist::class);
    }
}
