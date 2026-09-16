<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Treatment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What the public endpoint refuses, and why each refusal matters.
 *
 * These are not "the framework validates things" tests. Each one guards a way
 * that bad input would otherwise become bad data: a second row for a client who
 * already exists, a consent record with half of it missing, or a booking for a
 * treatment that was taken off the menu this morning.
 */
class InputValidationTest extends TestCase
{
    use RefreshDatabase;

    private function slot(): string
    {
        return CarbonImmutable::now('Asia/Bangkok')->addWeek()->setTime(14, 0)->toIso8601String();
    }

    private function payload(array $overrides = []): array
    {
        $branch = Branch::first();

        return array_replace_recursive([
            'branch_id' => $branch->id,
            'treatment_id' => Treatment::where('slug', 'signature-facial')->first()->id,
            'starts_at' => $this->slot(),
            'client' => ['name' => 'Suda', 'phone' => '0812345678'],
        ], $overrides);
    }

    public static function unusableNumbers(): array
    {
        return [['12'], ['not a phone'], ['0155555555'], ['08555555555'], ['']];
    }

    /**
     * RULE 12: an unreachable number is worse than no booking. Reception cannot
     * confirm it, and the client never hears from us.
     */
    #[DataProvider('unusableNumbers')]
    public function test_a_number_we_could_not_call_is_refused(string $phone): void
    {
        $this->seedClinic();

        $this->postJson('/api/bookings', $this->payload(['client' => ['phone' => $phone]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('client.phone');

        $this->assertSame(0, Booking::count());
    }

    /**
     * The duplicate-client bug this rule exists to stop.
     *
     * Same person, three ways of writing her number. If they did not normalise
     * she would become three clients - and her membership, which waives the
     * deposit, follows only one of them.
     */
    public function test_one_client_written_three_ways_is_still_one_client(): void
    {
        $this->seedClinic();

        foreach (['081 234 5678', '081-234-5678', '+66812345678'] as $i => $written) {
            $this->postJson('/api/bookings', $this->payload([
                'starts_at' => CarbonImmutable::now('Asia/Bangkok')->addWeek()->setTime(10 + $i * 2, 0)->toIso8601String(),
                'client' => ['name' => 'Suda', 'phone' => $written],
            ]))->assertCreated();
        }

        $this->assertSame(1, Client::where('phone', '+66812345678')->count());
        $this->assertSame(3, Booking::where('client_id', Client::where('phone', '+66812345678')->first()->id)->count());
    }

    /**
     * RULE 11 is all-or-nothing. Half a consent record is not a consent record,
     * and storing an ID number without the date of birth it was collected with
     * is exactly the PDPA exposure the proposal argues against.
     */
    public function test_half_a_consent_record_is_refused(): void
    {
        $this->seedClinic();

        $laser = Treatment::where('slug', 'laser-hair-removal')->first();

        $this->postJson('/api/bookings', $this->payload([
            'treatment_id' => $laser->id,
            'consent' => ['national_id' => '1234567890123'],
        ]))->assertStatus(422)->assertJsonValidationErrors('consent.date_of_birth');

        $this->postJson('/api/bookings', $this->payload([
            'treatment_id' => $laser->id,
            'consent' => ['date_of_birth' => '1990-01-01'],
        ]))->assertStatus(422)->assertJsonValidationErrors('consent.national_id');

        $this->assertSame(0, Booking::count());
    }

    public function test_a_date_of_birth_in_the_future_is_refused(): void
    {
        $this->seedClinic();

        $this->postJson('/api/bookings', $this->payload([
            'treatment_id' => Treatment::where('slug', 'laser-hair-removal')->first()->id,
            'consent' => ['national_id' => '1234567890123', 'date_of_birth' => now()->addYear()->toDateString()],
        ]))->assertStatus(422)->assertJsonValidationErrors('consent.date_of_birth');
    }

    /**
     * RULE 13. Nothing is deleted here, so deactivating is the only way to take
     * something off the menu - and it has to actually take it off the menu.
     */
    public function test_a_deactivated_treatment_is_neither_offered_nor_bookable(): void
    {
        $this->seedClinic();

        $branch = Branch::first();
        $treatment = Treatment::where('slug', 'signature-facial')->first();
        $date = CarbonImmutable::now('Asia/Bangkok')->addWeek()->toDateString();

        $before = $this->getJson("/api/availability?branch_id={$branch->id}&treatment_id={$treatment->id}&date={$date}");
        $this->assertNotEmpty($before->json('slots'), 'It should be bookable to begin with.');

        $treatment->update(['active' => false]);

        $this->getJson("/api/availability?branch_id={$branch->id}&treatment_id={$treatment->id}&date={$date}")
            ->assertOk()
            ->assertJsonCount(0, 'slots');

        $this->postJson('/api/bookings', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('reason', 'not_offered');

        // It is off the client-facing menu too, not just refused on submit.
        $this->assertNotContains(
            $treatment->id,
            collect($this->getJson("/api/treatments?branch_id={$branch->id}")->json())->pluck('id')->all()
        );
    }

    public function test_a_deactivated_branch_offers_nothing(): void
    {
        $this->seedClinic();

        $branch = Branch::first();
        // Built before the update: an UPDATE rewrites the row at the end of the
        // heap, so an unordered Branch::first() afterwards is a different branch.
        $payload = $this->payload();
        $branch->update(['active' => false]);

        $this->postJson('/api/bookings', $payload)
            ->assertStatus(422)
            ->assertJsonPath('reason', 'not_offered');

        $this->assertNotContains(
            $branch->id,
            collect($this->getJson('/api/branches')->json())->pluck('id')->all()
        );
    }

    public function test_a_name_too_short_to_be_a_name_is_refused(): void
    {
        $this->seedClinic();

        $this->postJson('/api/bookings', $this->payload(['client' => ['name' => 'A']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('client.name');
    }

    public function test_a_malformed_email_is_refused_but_no_email_is_fine(): void
    {
        $this->seedClinic();

        $this->postJson('/api/bookings', $this->payload(['client' => ['email' => 'not-an-email']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('client.email');

        $this->postJson('/api/bookings', $this->payload())->assertCreated();
    }
}
