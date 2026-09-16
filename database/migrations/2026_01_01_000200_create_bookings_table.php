<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();

            $table->foreignId('branch_id')->constrained();
            $table->foreignId('treatment_id')->constrained();
            $table->foreignId('therapist_id')->constrained();
            $table->foreignId('room_id')->constrained();
            $table->foreignId('client_id')->constrained();

            // pending_payment | confirmed | cancelled | expired | completed
            $table->string('status', 32)->index();

            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');

            // The resource is not free the moment the treatment ends. These two
            // columns are the actual blocked windows, and they are what the
            // exclusion constraints in the next migration compare. They are
            // written by the application, never by the client.
            $table->timestampTz('room_release_at');
            $table->timestampTz('therapist_release_at');

            // Snapshot of the buffers used, so changing a therapist's buffer
            // tomorrow does not silently move bookings taken today.
            $table->unsignedSmallInteger('room_cleanup_minutes');
            $table->unsignedSmallInteger('therapist_buffer_minutes');

            $table->boolean('deposit_required');
            $table->unsignedInteger('deposit_minor_units')->default(0);
            $table->timestampTz('deposit_paid_at')->nullable();
            $table->boolean('deposit_forfeited')->default(false);

            // Null once the booking is confirmed. While it is set and in the
            // future, the booking holds its slot.
            $table->timestampTz('hold_expires_at')->nullable();

            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            // online | reception - so the founder can see the split, and so the
            // receptionists keep a first-class way in.
            $table->string('created_via', 16)->default('online');

            $table->timestamps();

            $table->index(['branch_id', 'starts_at']);
            $table->index(['therapist_id', 'starts_at']);
        });

        // Supports the lazy hold sweep: finds only live, expiring holds, so the
        // sweep on each write stays a cheap index scan rather than a table scan.
        DB::statement(<<<'SQL'
            CREATE INDEX bookings_live_holds_idx
                ON bookings (hold_expires_at)
                WHERE status = 'pending_payment'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
