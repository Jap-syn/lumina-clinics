<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branches and treatments become deactivatable, like rooms and therapists.
 *
 * Nothing in this system is ever hard deleted. A branch has bookings, a
 * treatment has consent and payment records, and a therapist has a history a
 * client may ask about. Deleting any of them would either break a foreign key
 * or quietly destroy records that RULE 9 (money) and RULE 11 (consent) exist to
 * keep. Deactivating takes it out of the booking flow and leaves the past alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('phone');
        });

        Schema::table('treatments', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('requires_consent');
        });
    }

    public function down(): void
    {
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('active'));
        Schema::table('treatments', fn (Blueprint $table) => $table->dropColumn('active'));
    }
};
