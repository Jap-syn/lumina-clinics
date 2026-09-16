<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Therapist extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'buffer_minutes' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function treatments(): BelongsToMany
    {
        return $this->belongsToMany(Treatment::class);
    }
}
