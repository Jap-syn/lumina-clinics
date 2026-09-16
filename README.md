# Lumina Clinics — online booking (phase one)

A booking system for a six-branch aesthetics clinic. Laravel 12, PHP 8.3,
Postgres. No booking SaaS behind it: the availability logic, the API and the
storage are all here.

- **Proposal to the founder** — [`docs/proposal.md`](docs/proposal.md)
- **Technical notes** — [`docs/technical-notes.md`](docs/technical-notes.md)
- **Flowchart** — [`docs/flowchart.md`](docs/flowchart.md)

**Live:** `<!-- TODO: paste your Railway URL -->`
Client booking page at `/`, reception diary at `/diary`
(staff token: `lumina-reception`).

---

## The short version

The brief reads like a request for a booking page. The two things that actually
cost Lumina money are a double booking last Christmas and a payment link that
charges people twice. Both are concurrency problems, so both are solved at the
database rather than with an `if` statement:

- **No double booking.** A Postgres GiST exclusion constraint over the blocked
  time range. Two receptionists can both read "3pm is free" and both write; the
  database accepts exactly one.
- **No double charge.** An idempotency key plus two unique indexes, so a retried
  payment replays the first result instead of charging again.

Everything else — durations, cleanup, buffers, deposits, cancellation — is
ordinary application logic and lives in the services.

---

## Run it

Needs PHP 8.3+, Composer, and Postgres 14+ running locally.

```bash
git clone <this repo> && cd lumina
cp .env.example .env

composer install
php artisan key:generate

createdb lumina
createdb lumina_test          # the test suite uses its own database

php artisan migrate --seed
php artisan serve
```

Then:

| Screen | Where | Who |
|---|---|---|
| Book an appointment | <http://localhost:8000> | Anyone. No account. |
| Reception sign in | <http://localhost:8000/staff/login> | Token `lumina-reception` |
| Diary | `/staff/diary` | Reception. Pick a branch and a day, take a booking, cancel one. |
| Branches | `/staff/branches` | Opening hours and trading days |
| Treatments | `/staff/treatments` | Menu, durations, prices, which need consent |
| Therapists | `/staff/therapists` | Who works where, their turnaround, what they are trained on |

Only the first two need a URL. Everything behind the desk is reachable from the
navigation bar once you are signed in — there is nothing you have to know the
address of.

The token is exchanged for a session so the screens can be clicked through. The
JSON API still takes it as `Authorization: Bearer lumina-reception`, so the race
scripts and any integration keep working unchanged.

`./scripts/setup.sh` does all of the above in one command.

### Tests

```bash
php artisan test
```

Postgres, not SQLite — deliberately. SQLite has no exclusion constraints, so a
SQLite suite would be green while the most important rule in the system was
absent.

The concurrency tests fork real processes and need the `pcntl` extension
(standard on Linux and macOS CLI builds). They skip themselves with a clear
message if it is missing.

### Prove a rule really is enforced

```bash
./scripts/prove-failure.sh 01     # removes the no-double-booking rule
./scripts/prove-failure.sh 02     # removes the no-double-payment rule
```

Each applies a patch that deletes one rule, runs the test guarding it, saves the
failing output, restores the rule and re-runs to show green. See
[Tests that can fail](#tests-that-can-fail).

### Try the race against the deployment

```bash
./scripts/race.sh https://your-app.up.railway.app 1 4 2026-10-20T15:00:00+07:00 20
```

Twenty simultaneous bookings for one slot. Exactly one `201`, the rest `409`.

---

## Rules

Thirteen rules. Each one, and the file and line that enforces it.

| # | Rule | Enforced at |
|---|---|---|
| 1 | A room holds one client at a time, including its 15-minute cleanup | [`...000300_add_booking_overlap_constraints.php:33`](database/migrations/2026_01_01_000300_add_booking_overlap_constraints.php#L33) (constraint) · [`AvailabilityService.php:151`](app/Services/Availability/AvailabilityService.php#L151) (offered slots) |
| 2 | A therapist is in one place at a time, plus her own buffer — 0 for the senior facialist | [`...000300_add_booking_overlap_constraints.php:44`](database/migrations/2026_01_01_000300_add_booking_overlap_constraints.php#L44) (constraint) · [`AvailabilityService.php:135`](app/Services/Availability/AvailabilityService.php#L135) · set per person at [`TherapistRequest.php:8`](app/Http/Requests/Staff/TherapistRequest.php#L8) |
| 3 | A client cannot hold two overlapping bookings | [`...000300_add_booking_overlap_constraints.php:57`](database/migrations/2026_01_01_000300_add_booking_overlap_constraints.php#L57) |
| 4 | Starts on the hour or half hour, inside opening hours, on a trading day, not in the past, treatment ends by closing | [`BookingService.php:239`](app/Services/Booking/BookingService.php#L239), [`:250`](app/Services/Booking/BookingService.php#L250), [`:258`](app/Services/Booking/BookingService.php#L258) · [`AvailabilityService.php:57`](app/Services/Availability/AvailabilityService.php#L57), [`:83`](app/Services/Availability/AvailabilityService.php#L83), [`:87`](app/Services/Availability/AvailabilityService.php#L87), [`:227`](app/Services/Availability/AvailabilityService.php#L227) |
| 5 | The therapist must work at that branch and be qualified for that treatment | [`AvailabilityService.php:179`](app/Services/Availability/AvailabilityService.php#L179) |
| 6 | The room must be equipped for that treatment | [`AvailabilityService.php:194`](app/Services/Availability/AvailabilityService.php#L194) |
| 7 | Non-members pay a 300 deposit and hold the slot until they do; members are confirmed immediately | [`BookingService.php:112`](app/Services/Booking/BookingService.php#L112) · [`:187`](app/Services/Booking/BookingService.php#L187) |
| 8 | An unpaid hold expires after 10 minutes and releases its slot — with no scheduled job | [`HoldSweeper.php:9`](app/Services/Booking/HoldSweeper.php#L9) |
| 9 | A deposit is charged at most once per booking | [`DepositService.php:12`](app/Services/Payments/DepositService.php#L12) · unique indexes in [`...000400_create_payments_and_consents_tables.php`](database/migrations/2026_01_01_000400_create_payments_and_consents_tables.php) |
| 10 | Free cancellation 24 hours or more ahead; inside that the deposit is forfeited | [`Booking.php:105`](app/Models/Booking.php#L105) · [`BookingService.php:203`](app/Services/Booking/BookingService.php#L203) · [`DepositService.php:134`](app/Services/Payments/DepositService.php#L134) |
| 11 | Laser treatments need a consent record; the ID number is encrypted at rest | [`BookingService.php:49`](app/Services/Booking/BookingService.php#L49) · cast in [`Consent.php`](app/Models/Consent.php) |
| 12 | A phone number must be reachable, and is stored in one canonical E.164 form | [`E164Phone.php:10`](app/Rules/E164Phone.php#L10) (accepted) · [`Client.php:22`](app/Models/Client.php#L22) (stored) · [`PhoneNumber.php`](app/Support/PhoneNumber.php) |
| 13 | Nothing is deleted; a branch, treatment or therapist is deactivated and stops being offered | [`AvailabilityService.php:50`](app/Services/Availability/AvailabilityService.php#L50) · [`BookingService.php:230`](app/Services/Booking/BookingService.php#L230) |

Rules 1, 2, 3 and 9 are in the database. The criterion: **a rule belongs in the
database when two simultaneous requests can each satisfy it individually while
jointly violating it.** Everything else is a pure function of one request, so it
lives in the application where the error messages are better.

Rule 12 is in neither place by accident. A phone number is a client's identity
here — `clients.phone` is unique and returning clients are matched on it — so
it is normalised on the way in rather than validated and stored as typed.
Without that, one woman who writes her number three ways is three clients with
three different membership flags, and the deposit rule follows only one of them.

---

## Tests that can fail

### 1. The double booking rule

`docs/patches/01-remove-room-overlap-constraint.patch` deletes the exclusion
constraint that stops two clients being put in one room. The application's own
availability check is left completely intact — which is the point. It is not
enough, and removing the constraint proves it.

**The diff:**

```diff
--- a/database/migrations/2026_01_01_000300_add_booking_overlap_constraints.php
+++ b/database/migrations/2026_01_01_000300_add_booking_overlap_constraints.php
@@ -30,17 +30,6 @@
         // range. Ships with Postgres as a standard contrib module.
         DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
 
-        // RULE 1: one client per room at a time, including the 15 minute clean up.
-        DB::statement(<<<'SQL'
-            ALTER TABLE bookings
-            ADD CONSTRAINT bookings_no_room_overlap
-            EXCLUDE USING gist (
-                room_id WITH =,
-                tstzrange(starts_at, room_release_at, '[)') WITH &&
-            )
-            WHERE (status IN ('pending_payment', 'confirmed'))
-        SQL);
-
         // RULE 2: a therapist is in one place at a time, plus her own turnaround.
         // The senior facialist's buffer is 0, so she can go straight into her
         // next client - in a different room, because rule 1 still holds.
```

**The failing output:**

```text
<!-- TODO: run ./scripts/prove-failure.sh 01 and paste docs/failing-output-01.txt here -->
```

Three tests fail: the two-connection test, the twenty-parallel-process test, and
the room cleanup test. All twenty forked processes pass their own availability
check, because they all read before any of them writes.

### 2. The double payment rule

`docs/patches/02-remove-double-payment-guard.patch` removes the already-paid
check *and* the unique index behind it. Two checkout attempts with different
keys — two browser tabs — then charge the client twice.

**The diff:**

```diff
--- a/app/Services/Payments/DepositService.php
+++ b/app/Services/Payments/DepositService.php
@@ -54,15 +54,6 @@
             ];
         }
 
-        // 2. Different attempt, booking already paid. Do not charge again.
-        if ($paid = $booking->successfulPayment()) {
-            return [
-                'payment' => $paid,
-                'booking' => $booking->refresh(),
-                'replayed' => true,
-            ];
-        }
-
         if ($booking->status === Booking::CONFIRMED && ! $booking->deposit_required) {
             // A member's booking. Nothing to pay.
             return ['payment' => null, 'booking' => $booking, 'replayed' => true];
--- a/database/migrations/2026_01_01_000400_create_payments_and_consents_tables.php
+++ b/database/migrations/2026_01_01_000400_create_payments_and_consents_tables.php
@@ -44,12 +44,6 @@
             $table->timestamps();
         });
 
-        DB::statement(<<<'SQL'
-            CREATE UNIQUE INDEX payments_one_success_per_booking
-                ON payments (booking_id)
-                WHERE status = 'succeeded'
-        SQL);
-
         /*
          | Consent records.
          |
```

**The failing output:**

```text
<!-- TODO: run ./scripts/prove-failure.sh 02 and paste docs/failing-output-02.txt here -->
```

Worth noting what this patch does *not* break: the same-key retry test still
passes, because the idempotency key index catches it. The two guards are
independent, and only removing both exposes the founder's actual complaint.

---

## What the tests cover

| File | Covers |
|---|---|
| `DoubleBookingTest` | Two live connections racing for one room; twenty forked processes racing through the real service; cancellation releasing a slot |
| `AvailabilityTest` | 15-minute room cleanup; senior facialist working back to back; a buffered therapist not able to; off-grid times; 90-minute treatments near closing; unqualified therapists never offered; past times; closed days |
| `DepositAndCancellationTest` | Member vs non-member; same-key retry charging once; two keys charging once; failed charge rolling back cleanly; hold expiry with no scheduler; confirmed bookings never swept; refund at 25h, forfeit at 12h, free at exactly 24h |
| `BookingApiTest` | The endpoints end to end; consent required for laser; ID number unreadable in the table; client double-booking refused; staff routes closed without a token; reception booking on behalf of a caller |
| `InputValidationTest` | Unreachable phone numbers refused; one client written three ways staying one client; half a consent record refused; a future date of birth refused; a deactivated branch or treatment offered nowhere and bookable nowhere |
| `StaffAreaTest` | Every staff screen closed to a stranger; the wrong token refused; sign in and out; the API still taking a bearer token; branch created, edited and deactivated but never deleted; a branch closing before it opens refused; a 45-minute treatment refused; a therapist with no treatments refused; reception taking a booking at the desk; reception told no when the slot has gone |
| `PhoneNumberTest` | Eight ways of writing one Thai number all normalising to the same string; landlines; foreign numbers kept in E.164; seven kinds of unusable input refused |

---

## Not done

Honest list of what is missing, thin, or deferred.

**Left out on purpose**

- **Reminders (SMS/email).** Needs something running in the background, which
  the hosting explicitly cannot do. This is the biggest single thing the
  constraint costs, and the first reason to revisit hosting.
- **Client-side rescheduling.** Cancel and rebook works; moving a booking in
  place does not.
- **Memberships and packages.** The system honours `is_member` but does not sell
  or track memberships.
- **Staff rotas, shifts and holidays.** Therapists are currently assumed
  available during branch opening hours. This is the largest gap between the
  model and a real clinic, and the first thing I would build next.
- **Reporting.** No utilisation or revenue views.
- **Room management.** Branches, treatments and therapists can be managed from
  the staff area; rooms cannot. They are still seeded. This is the one obvious
  hole in the catalogue screens: a branch created in the UI has no rooms, so it
  offers nothing until rooms are added by hand. It is the same pattern as the
  other three and is the next screen to build.

**Thin**

- **Auth — one shared credential, and no identity.** There is a real gate
  (`StaffToken`, timing-safe, session or bearer, throttled login) but only one
  key, and it is branch-blind: whoever holds it opens all six diaries. Nothing
  records *who* took a booking, only that it came from `reception` rather than
  online, because there is no "who" to record yet.

  The diary shows client names and phone numbers, and the consent table holds
  national ID numbers. So the position is conditional, and it is argued in the
  proposal rather than buried here: **if phase one goes live holding consent
  records, per-person logins are a launch condition, not a phase-two item.** If
  the recommendation to store only "consent given, on this date" is accepted,
  the shared token is acceptable for the six weeks.
- **The payment gateway is a stub.** `FakeGateway` implements the interface;
  swapping in Omise or 2C2P is one class and one binding in `AppServiceProvider`.
  The idempotency work around it is real and is where the value is.
- **No rate limiting** on the public booking endpoint. Trivial to add with
  Laravel's throttle middleware; left out deliberately so the race scripts
  measure the database rather than the throttle. The staff login *is* throttled,
  because a single shared secret is worth guessing.
- **Phone validation is a documented subset.** `PhoneNumber` checks the Thai
  numbering plan properly and accepts any well-formed E.164 elsewhere, rather
  than guessing at another country's plan. A production system would use
  libphonenumber; this is roughly 60 lines with no dependency, and the tests say
  exactly what it does and does not accept.
- **The front end is plain by design, not by neglect.** Server-rendered Blade,
  Tailwind from the CDN, a little vanilla JavaScript for the availability
  picker. No build step and no asset pipeline, because the hosting that cannot
  run a cron job should not be asked to run `npm run build` either. Tailwind
  advise against the CDN in production; swapping it for a built stylesheet is a
  deploy-step change, not a rewrite.

**Known gap worth naming**

- If the payment gateway succeeds but its response is lost, the transaction
  rolls back and no record survives of money that did move. Fixing this properly
  needs a reconciliation job against the provider — which needs background
  processing. Same constraint, same recommendation.

**What I would do next, in order**

1. Per-user staff accounts with branch roles — sooner than "next" if consent
   records go live, for the reason above.
2. Room management, to close the one hole in the catalogue screens.
3. Therapist working hours and time off.
4. Reminders, once hosting can run a worker — and reconciliation with it.
5. Rescheduling in place.

---

## Assumptions

Things the call did not cover, which I chose rather than guessed silently. All
are in the proposal as items to confirm at kickoff.

- Branch hours 10:00–20:00, seven days. The six branch names are invented.
- A treatment must *end* by closing time; cleanup may run past it.
- Three rooms per branch: two facial, one laser suite. The second facial room is
  what makes the senior facialist's back-to-back working possible.
- Holds last 10 minutes.
- Clients are identified by phone number, which is how reception already works.
- Deposit is 300 THB, stored in satang as an integer.
- Cancellation at exactly 24 hours is free — the boundary favours the client.

---

## AI

I used Claude (Anthropic) throughout, and reviewed and ran everything it
produced. The honest version of that is more useful than a list of features, so:

**What it was used for**

- Working through the brief: which bullets were requirements and which were
  traps. The no-scheduled-jobs line and the split between room cleanup and
  therapist buffer both came out of that discussion, and both shaped the schema.
- Drafting the exclusion-constraint approach and verifying it against a real
  Postgres 16 instance before the application was built around it: that the
  range expression is immutable and legal in a constraint, that it blocks a
  booking starting inside the cleanup window, that two concurrent transactions
  yield exactly one row, and that a cancelled row stops blocking.
- Drafting the application code, the migrations, the tests and these documents.
- Generating the two rule-removal patches as real diffs rather than by hand.

**Running the suite, and what that cost me**

The tests were written alongside the code but were not run end to end until
late, and running them properly found four real defects. This is the part I
would most want to be asked about, because it is the clearest evidence in the
repository of why "tests that can fail" is the right thing to ask for.

1. **Every booking was stored at the wrong instant.** Every business timestamp
   is `timestamptz` and the application reasons in branch-local time, but
   Laravel's default date format is `Y-m-d H:i:s`: a `CarbonImmutable` carrying
   `+07:00` was written as the bare string `2026-09-23 14:00:00` and Postgres
   read it in the *session* timezone. The offset was dropped, not applied. The
   symptom changed with the machine — seven hours out on a UTC server, thirty
   minutes out on a laptop set to `Asia/Rangoon` — which is exactly how a bug
   like this survives a casual test run. Fixed centrally in
   [`PostgresTimestampTzGrammar`](app/Database/PostgresTimestampTzGrammar.php),
   which keeps the offset on both query bindings and Eloquent attributes.
2. **A concurrency test deadlocked against itself.** An exclusion constraint does
   not reject a conflict with an *uncommitted* row; it blocks the second writer
   until the first transaction ends. The test inserted on connection A, then
   inserted on connection B, then committed A — and never reached the commit,
   because the single process was parked inside B's insert. It hung rather than
   failed, which is worse. The expectation was right; the choreography was
   impossible, and is now ordered so the read happens during the race and the
   write happens after it.
3. **Two tests failed a precondition they were not testing.** Both booked laser
   treatments without a consent record, so RULE 11 refused them before the
   overlap logic was ever reached. In the twenty-process race that meant zero
   bookings survived, for entirely the wrong reason.
4. **The client-overlap case returned the wrong status.** 409 instead of 422,
   downstream of (1): availability could not see the first booking, so the retry
   loop kept choosing the same room and hit the room constraint three times
   instead of reaching the client constraint.

**What I did**

- Decided the data model, the rule list, and what belongs in the database versus
  the application.
- Ran the migrations and the full suite against real Postgres, found the four
  defects above, and fixed them — the code for the first, the tests for the
  others, and only after being able to say why each expectation was wrong.
- Wrote the assumptions and the "Not done" list. Those are judgement calls about
  scope and I would defend each one at kickoff.

The parts I would most want to be questioned on are the lazy hold sweep (a real
trade-off, taken because of the hosting constraint), the decision to store
national ID numbers at all — which I have recommended against in the proposal —
and the timezone fix above, which is the kind of defect that passes review and
fails in production.
