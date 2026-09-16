<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Client;
use App\Models\Room;
use App\Models\Therapist;
use App\Models\Treatment;
use Illuminate\Database\Seeder;

/**
 * Enough real-shaped data to click through the system.
 *
 * The numbers here are guesses where the call did not tell us - branch names,
 * opening hours, how many rooms. They are marked in docs/proposal.md as things
 * to confirm at kickoff. What is NOT a guess: the 15 minute room clean up, the
 * 300 deposit, the 30/60/90 durations, and the senior facialist's zero buffer.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $treatments = collect([
            ['name' => 'Express Facial', 'slug' => 'express-facial', 'duration_minutes' => 30, 'price_minor_units' => 120000, 'requires_consent' => false],
            ['name' => 'Signature Facial', 'slug' => 'signature-facial', 'duration_minutes' => 60, 'price_minor_units' => 220000, 'requires_consent' => false],
            ['name' => 'Deep Cleanse & Peel', 'slug' => 'deep-cleanse-peel', 'duration_minutes' => 90, 'price_minor_units' => 350000, 'requires_consent' => false],
            ['name' => 'Laser Hair Removal', 'slug' => 'laser-hair-removal', 'duration_minutes' => 30, 'price_minor_units' => 250000, 'requires_consent' => true],
            ['name' => 'Laser Resurfacing', 'slug' => 'laser-resurfacing', 'duration_minutes' => 60, 'price_minor_units' => 480000, 'requires_consent' => true],
        ])->map(fn ($t) => Treatment::create($t));

        $facials = $treatments->whereIn('slug', ['express-facial', 'signature-facial', 'deep-cleanse-peel']);
        $lasers = $treatments->whereIn('slug', ['laser-hair-removal', 'laser-resurfacing']);

        $branches = [
            ['name' => 'Lumina Sukhumvit', 'slug' => 'sukhumvit'],
            ['name' => 'Lumina Silom', 'slug' => 'silom'],
            ['name' => 'Lumina Thonglor', 'slug' => 'thonglor'],
            ['name' => 'Lumina Ari', 'slug' => 'ari'],
            ['name' => 'Lumina Rama IX', 'slug' => 'rama-ix'],
            ['name' => 'Lumina Phuket', 'slug' => 'phuket'],
        ];

        foreach ($branches as $index => $attributes) {
            $branch = Branch::create($attributes + [
                'timezone' => 'Asia/Bangkok',
                'opens_at' => '10:00',
                'closes_at' => '20:00',
                'open_weekdays' => [1, 2, 3, 4, 5, 6, 7],
            ]);

            // Two facial rooms and one laser suite per branch. The second facial
            // room is what lets the senior facialist run back to back: she needs
            // no break, but the room she has just used still needs 15 minutes.
            $facialRoomA = Room::create(['branch_id' => $branch->id, 'name' => 'Treatment Room 1', 'cleanup_minutes' => 15]);
            $facialRoomB = Room::create(['branch_id' => $branch->id, 'name' => 'Treatment Room 2', 'cleanup_minutes' => 15]);
            $laserSuite = Room::create(['branch_id' => $branch->id, 'name' => 'Laser Suite', 'cleanup_minutes' => 15]);

            $facialRoomA->treatments()->sync($facials->pluck('id'));
            $facialRoomB->treatments()->sync($facials->pluck('id'));
            $laserSuite->treatments()->sync($lasers->pluck('id'));

            // The senior facialist. "She preps the next one while the laser
            // cools down, so back to back is fine for her."
            $senior = Therapist::create([
                'branch_id' => $branch->id,
                'name' => ['Nok', 'Ploy', 'Mint', 'Fah', 'Bee', 'Som'][$index],
                'title' => 'Senior Facialist',
                'buffer_minutes' => 0,
            ]);
            $senior->treatments()->sync($treatments->pluck('id'));

            $therapist = Therapist::create([
                'branch_id' => $branch->id,
                'name' => ['May', 'Ying', 'Nut', 'Aom', 'Gift', 'Jum'][$index],
                'title' => 'Therapist',
                'buffer_minutes' => 15,
            ]);
            $therapist->treatments()->sync($facials->pluck('id'));

            $laserTech = Therapist::create([
                'branch_id' => $branch->id,
                'name' => ['Ann', 'Nan', 'Pim', 'Wan', 'Jib', 'Tan'][$index],
                'title' => 'Laser Technician',
                'buffer_minutes' => 15,
            ]);
            $laserTech->treatments()->sync($lasers->pluck('id'));
        }

        Client::create(['name' => 'Walk-in Test Client', 'phone' => '0800000001', 'email' => 'test@example.com', 'is_member' => false]);
        Client::create(['name' => 'Member Test Client', 'phone' => '0800000002', 'email' => 'member@example.com', 'is_member' => true]);
    }
}
