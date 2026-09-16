<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const SUCCEEDED = 'succeeded';
    public const REFUNDED = 'refunded';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'response_snapshot' => 'array',
            'refunded_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
