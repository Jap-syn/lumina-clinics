<?php

namespace App\Services\Payments;

use Illuminate\Support\Str;

/**
 * Stands in for the real payment provider for phase one.
 *
 * Every charge is recorded so tests can assert how many times money actually
 * moved - which is the whole point of the idempotency work. $failNext lets a
 * test simulate the hang the founder described.
 */
class FakeGateway implements PaymentGateway
{
    /** @var array<int, array{amount: int, description: string, reference: string}> */
    public array $charges = [];

    /** @var array<int, string> */
    public array $refunds = [];

    public bool $failNext = false;

    public function charge(int $amountMinorUnits, string $currency, string $description): string
    {
        if ($this->failNext) {
            $this->failNext = false;

            throw new PaymentFailedException('Gateway timed out');
        }

        $reference = 'fake_'.Str::lower(Str::random(16));

        $this->charges[] = [
            'amount' => $amountMinorUnits,
            'description' => $description,
            'reference' => $reference,
        ];

        return $reference;
    }

    public function refund(string $providerReference, int $amountMinorUnits): string
    {
        $this->refunds[] = $providerReference;

        return 'fake_refund_'.Str::lower(Str::random(12));
    }

    public function chargeCount(): int
    {
        return count($this->charges);
    }
}
