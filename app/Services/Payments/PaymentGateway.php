<?php

namespace App\Services\Payments;

interface PaymentGateway
{
    /**
     * Charge the deposit. Returns the provider's reference.
     *
     * Implementations are assumed to be unreliable: they may time out after the
     * money has moved. Idempotency is handled by the caller, not here.
     *
     * @throws PaymentFailedException
     */
    public function charge(int $amountMinorUnits, string $currency, string $description): string;

    public function refund(string $providerReference, int $amountMinorUnits): string;
}
