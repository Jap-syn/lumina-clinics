<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Sometimes the page hangs and people pay twice, then we refund by hand."
 *
 * Two separate guards, because they catch two different failures:
 *
 *  1. idempotency_key unique - the same attempt retried (client retries, the
 *     browser resends, the page hangs and the client hits pay again). The retry
 *     returns the first result instead of charging.
 *
 *  2. one succeeded payment per booking - two genuinely different attempts
 *     racing for one booking. Belt and braces: even a client that generates a
 *     fresh key per click cannot be charged twice for the same booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            // Supplied by the caller, generated once per checkout attempt.
            $table->string('idempotency_key')->unique();

            $table->unsignedInteger('amount_minor_units');
            $table->string('currency', 3)->default('THB');

            // succeeded | refunded
            $table->string('status', 16);

            $table->string('provider_reference')->nullable();

            // The response we returned first time, replayed verbatim on a retry.
            $table->json('response_snapshot')->nullable();

            $table->timestampTz('refunded_at')->nullable();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payments_one_success_per_booking
                ON payments (booking_id)
                WHERE status = 'succeeded'
        SQL);

        /*
         | Consent records.
         |
         | "The laser consent form asks for the client's ID number and date of
         |  birth, so they want the system to keep those too."
         |
         | Kept in their own table rather than on clients, so that the identity
         | data is only ever created for the laser bookings that legally need it,
         | is trivial to find and delete on request, and never rides along with
         | an ordinary booking lookup. The ID number is encrypted at rest by the
         | model cast. See the data protection note in docs/proposal.md.
         */
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('national_id');          // encrypted cast on the model
            $table->date('date_of_birth');
            $table->timestampTz('signed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
        Schema::dropIfExists('payments');
    }
};
