<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Therapist;
use App\Models\Treatment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The reception area: the gate on it, and the catalogue screens behind it.
 *
 * The gate is one shared token by design (see the proposal). What is tested
 * here is that it actually gates - a page that looks protected and is not is
 * worse than no page at all - and that nothing in the catalogue can be deleted.
 */
class StaffAreaTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        $this->post(route('staff.login.store'), ['token' => config('lumina.staff_token')])
            ->assertRedirect(route('staff.diary'));
    }

    public function test_every_staff_screen_is_closed_to_a_stranger(): void
    {
        $this->seedClinic();

        foreach ([
            route('staff.diary'),
            route('staff.branches.index'),
            route('staff.treatments.index'),
            route('staff.therapists.index'),
            route('staff.branches.create'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('staff.login'));
        }
    }

    public function test_the_wrong_token_does_not_open_the_door(): void
    {
        $this->from(route('staff.login'))
            ->post(route('staff.login.store'), ['token' => 'not-the-token'])
            ->assertRedirect(route('staff.login'))
            ->assertSessionHasErrors('token');

        $this->get(route('staff.branches.index'))->assertRedirect(route('staff.login'));
    }

    public function test_signing_in_and_out_opens_and_closes_the_area(): void
    {
        $this->seedClinic();

        $this->signIn();
        $this->get(route('staff.branches.index'))->assertOk()->assertSee('Branches');

        $this->post(route('staff.logout'))->assertRedirect(route('staff.login'));
        $this->get(route('staff.branches.index'))->assertRedirect(route('staff.login'));
    }

    /** The JSON API still takes the bearer token, so scripts keep working. */
    public function test_the_api_still_accepts_the_bearer_token(): void
    {
        $this->seedClinic();

        $query = 'branch_id='.Branch::first()->id.'&date=2026-10-01';

        $this->getJson("/api/staff/diary?{$query}")->assertStatus(401);

        $this->withHeader('Authorization', 'Bearer '.config('lumina.staff_token'))
            ->getJson("/api/staff/diary?{$query}")
            ->assertOk();
    }

    /** An API route answers as an API even when the caller sends no Accept header. */
    public function test_the_api_refuses_in_json_even_without_an_accept_header(): void
    {
        $this->seedClinic();

        $this->get('/api/staff/diary?branch_id='.Branch::first()->id.'&date=2026-10-01')
            ->assertStatus(401)
            ->assertJsonPath('error', 'Staff authentication required.');
    }

    public function test_a_branch_can_be_created_edited_and_deactivated_but_never_deleted(): void
    {
        $this->seedClinic();
        $this->signIn();

        $this->post(route('staff.branches.store'), [
            'name' => 'Lumina Phuket', 'slug' => 'phuket-town', 'timezone' => 'Asia/Bangkok',
            'opens_at' => '09:00', 'closes_at' => '19:00', 'open_weekdays' => [1, 2, 3, 4, 5],
            'phone' => '076 123 456', 'active' => '1',
        ])->assertRedirect(route('staff.branches.index'));

        $branch = Branch::where('slug', 'phuket-town')->firstOrFail();
        $this->assertTrue($branch->active);
        // RULE 12 applies to the branch's own number too.
        $this->assertSame('+6676123456', $branch->phone);

        $this->put(route('staff.branches.update', $branch), [
            'name' => 'Lumina Phuket Town', 'slug' => 'phuket-town', 'timezone' => 'Asia/Bangkok',
            'opens_at' => '09:00', 'closes_at' => '20:00', 'open_weekdays' => [1, 2, 3, 4, 5, 6],
            // The form always posts this box; an absent one means "unticked".
            'active' => '1',
        ])->assertRedirect(route('staff.branches.index'));

        $this->assertSame('Lumina Phuket Town', $branch->fresh()->name);

        $this->patch(route('staff.branches.toggle', $branch));
        $this->assertFalse($branch->fresh()->active);
        // The row is still there. That is the point.
        $this->assertNotNull(Branch::find($branch->id));
    }

    public function test_a_branch_that_closes_before_it_opens_is_refused(): void
    {
        $this->seedClinic();
        $this->signIn();

        $this->from(route('staff.branches.create'))->post(route('staff.branches.store'), [
            'name' => 'Backwards', 'slug' => 'backwards', 'timezone' => 'Asia/Bangkok',
            'opens_at' => '20:00', 'closes_at' => '10:00', 'open_weekdays' => [1],
        ])->assertSessionHasErrors('closes_at');

        $this->assertNull(Branch::where('slug', 'backwards')->first());
    }

    public function test_a_treatment_must_fit_the_half_hour_grid(): void
    {
        $this->seedClinic();
        $this->signIn();

        // 45 minutes would push every following start off the grid.
        $this->post(route('staff.treatments.store'), [
            'name' => 'Odd One', 'slug' => 'odd-one', 'duration_minutes' => 45, 'price_baht' => '1500',
        ])->assertSessionHasErrors('duration_minutes');

        $this->post(route('staff.treatments.store'), [
            'name' => 'Express Peel', 'slug' => 'express-peel', 'duration_minutes' => 30, 'price_baht' => '1500.50',
        ])->assertRedirect(route('staff.treatments.index'));

        // Baht in, satang stored.
        $this->assertSame(150050, Treatment::where('slug', 'express-peel')->first()->price_minor_units);
    }

    public function test_a_therapist_needs_at_least_one_treatment(): void
    {
        $this->seedClinic();
        $this->signIn();

        $branch = Branch::first();

        $this->post(route('staff.therapists.store'), [
            'branch_id' => $branch->id, 'name' => 'Untrained', 'buffer_minutes' => 15, 'treatments' => [],
        ])->assertSessionHasErrors('treatments');

        $facial = Treatment::where('slug', 'signature-facial')->firstOrFail();

        $this->post(route('staff.therapists.store'), [
            'branch_id' => $branch->id, 'name' => 'Ploy', 'title' => 'Therapist',
            'buffer_minutes' => 0, 'treatments' => [$facial->id], 'active' => '1',
        ])->assertRedirect(route('staff.therapists.index'));

        $therapist = Therapist::where('name', 'Ploy')->firstOrFail();
        $this->assertSame(0, $therapist->buffer_minutes);
        $this->assertTrue($therapist->treatments->contains($facial));
    }

    /**
     * The receptionists' answer to "does this replace us?".
     *
     * A booking taken at the desk goes through the same service the website
     * calls - same overlap rules, same consent check - with the one thing she
     * can do that a client cannot: confirm it because the cash is in her hand.
     */
    public function test_reception_can_take_a_booking_from_the_diary_screen(): void
    {
        $this->seedClinic();
        $this->signIn();

        $branch = Branch::first();
        $treatment = Treatment::where('slug', 'signature-facial')->firstOrFail();
        $slot = CarbonImmutable::now($branch->timezone)->addWeek()->setTime(14, 0);

        $this->post(route('staff.diary.store'), [
            'branch_id' => $branch->id,
            'treatment_id' => $treatment->id,
            'starts_at' => $slot->format('Y-m-d\TH:i'),
            'name' => 'Called In',
            'phone' => '081 234 5678',
            'deposit_taken' => '1',
        ])->assertRedirect();

        $booking = Booking::firstOrFail();
        $this->assertSame(Booking::CONFIRMED, $booking->status, 'Deposit taken at the desk confirms it.');
        $this->assertSame('reception', $booking->created_via);
        $this->assertSame('+66812345678', $booking->client->phone);

        // And it is on the screen, at the branch's local time.
        $this->get(route('staff.diary', ['branch_id' => $branch->id, 'date' => $slot->toDateString()]))
            ->assertOk()
            ->assertSee('Called In')
            ->assertSee('14:00');
    }

    public function test_reception_cannot_book_a_slot_that_is_already_gone(): void
    {
        $this->seedClinic();
        $this->signIn();

        $branch = Branch::first();
        $laser = Treatment::where('slug', 'laser-hair-removal')->firstOrFail();
        $slot = CarbonImmutable::now($branch->timezone)->addWeek()->setTime(15, 0);

        $payload = fn (string $name, string $phone) => [
            'branch_id' => $branch->id,
            'treatment_id' => $laser->id,
            'starts_at' => $slot->format('Y-m-d\TH:i'),
            'name' => $name,
            'phone' => $phone,
            'national_id' => '1234567890123',
            'date_of_birth' => '1990-01-01',
        ];

        $this->post(route('staff.diary.store'), $payload('First', '0811111111'))->assertRedirect();

        // One laser suite, so the second caller has to be told no - on screen,
        // not with a 500.
        $this->from(route('staff.diary'))
            ->post(route('staff.diary.store'), $payload('Second', '0822222222'))
            ->assertRedirect(route('staff.diary'))
            ->assertSessionHas('error');

        $this->assertSame(1, Booking::blocking()->count());
    }
}
