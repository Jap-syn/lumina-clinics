<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Booking extends Model
{
    /** Holding a slot while the deposit is paid. */
    public const PENDING_PAYMENT = 'pending_payment';

    /** Deposit taken, or client is a member. This is a real appointment. */
    public const CONFIRMED = 'confirmed';

    public const CANCELLED = 'cancelled';

    /** The hold ran out before the deposit arrived. Released the slot. */
    public const EXPIRED = 'expired';

    public const COMPLETED = 'completed';

    /**
     * The statuses that occupy a room and a therapist. This list is mirrored by
     * the WHERE clause of the exclusion constraints in
     * 2026_01_01_000300_add_booking_overlap_constraints.php - change one and you
     * must change the other.
     */
    public const BLOCKING = [self::PENDING_PAYMENT, self::CONFIRMED];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'room_release_at' => 'datetime',
            'therapist_release_at' => 'datetime',
            'hold_expires_at' => 'datetime',
            'deposit_paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'deposit_required' => 'boolean',
            'deposit_forfeited' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    public function therapist(): BelongsTo
    {
        return $this->belongsTo(Therapist::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function consent(): HasOne
    {
        return $this->hasOne(Consent::class);
    }

    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', self::BLOCKING);
    }

    public static function newReference(): string
    {
        // Short, unambiguous when read down a phone line: no O/0/I/1.
        return 'LUM-'.Str::upper(Str::random(3)).Str::upper(Str::random(3));
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, self::BLOCKING, true);
    }

    /**
     * RULE 10: "Free cancellation up to 24 hours before."
     *
     * At or outside the window the deposit comes back. Inside it, the deposit is
     * kept - the room and the therapist were held and cannot be resold this late.
     */
    public function qualifiesForRefund(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        $deadline = $this->starts_at->copy()
            ->subHours(config('lumina.free_cancellation_hours'));

        return $now->lessThanOrEqualTo($deadline);
    }

    public function successfulPayment(): ?Payment
    {
        return $this->payments()->where('status', Payment::SUCCEEDED)->first();
    }
}
