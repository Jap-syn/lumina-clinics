<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'open_weekdays' => 'array',
            'active' => 'boolean',
        ];
    }

    protected function phone(): Attribute
    {
        return Attribute::set(fn ($value) => $value === null ? null : (PhoneNumber::normalise($value) ?? $value));
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function therapists(): HasMany
    {
        return $this->hasMany(Therapist::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** ISO-8601 weekday number, 1 = Monday. */
    public function opensOnWeekday(int $isoWeekday): bool
    {
        return in_array($isoWeekday, $this->open_weekdays, true);
    }
}
