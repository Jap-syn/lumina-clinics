<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_member' => 'boolean',
        ];
    }

    /**
     * RULE 12: the number is stored in one canonical form.
     *
     * A client is identified by phone (`clients.phone` is unique and
     * BookingController matches on it), so "085-555-5555" and "+66855555555"
     * must not become two people with two different member flags. Normalising
     * here rather than in the controller means reception, the website and the
     * seeder all land on the same row.
     */
    protected function phone(): Attribute
    {
        return Attribute::set(fn ($value) => PhoneNumber::normalise($value) ?? $value);
    }

    /** The familiar local form, for screens and for reading down a phone line. */
    public function formattedPhone(): string
    {
        return PhoneNumber::format($this->phone) ?? $this->phone;
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
