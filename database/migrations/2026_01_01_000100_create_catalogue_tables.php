<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone')->default('Asia/Bangkok');
            $table->time('opens_at')->default('10:00');
            $table->time('closes_at')->default('20:00');
            // ISO-8601 weekday numbers the branch trades on (1 = Monday).
            $table->json('open_weekdays');
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        Schema::create('treatments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // "Treatments are 30, 60, or 90 minutes."
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedInteger('price_minor_units');
            // Laser treatments need the ID number / date of birth consent record.
            $table->boolean('requires_consent')->default(false);
            $table->timestamps();
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // "A room takes one client at a time and needs 15 minutes to clean up after."
            $table->unsignedSmallInteger('cleanup_minutes')->default(15);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Not every room can host every treatment: the laser lives in one suite.
        Schema::create('room_treatment', function (Blueprint $table) {
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treatment_id')->constrained()->cascadeOnDelete();
            $table->primary(['room_id', 'treatment_id']);
        });

        Schema::create('therapists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('title')->nullable();
            // Per-therapist turnaround. The senior facialist is set to 0:
            // "back to back is fine for her". Everyone else defaults to 15.
            $table->unsignedSmallInteger('buffer_minutes')->default(15);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('therapist_treatment', function (Blueprint $table) {
            $table->foreignId('therapist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('treatment_id')->constrained()->cascadeOnDelete();
            $table->primary(['therapist_id', 'treatment_id']);
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone');
            $table->string('email')->nullable();
            // "Members don't pay it."
            $table->boolean('is_member')->default(false);
            $table->timestamps();
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
        Schema::dropIfExists('therapist_treatment');
        Schema::dropIfExists('therapists');
        Schema::dropIfExists('room_treatment');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('treatments');
        Schema::dropIfExists('branches');
    }
};
