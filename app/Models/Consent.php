<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        // Identity data is encrypted at rest. A database dump or a stray backup
        // does not hand anyone a list of ID numbers.
        return [
            'national_id' => 'encrypted',
            'date_of_birth' => 'date',
            'signed_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
