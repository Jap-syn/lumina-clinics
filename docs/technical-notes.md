# Technical notes

For the developer taking this over. Covers the rules the system enforces, where
each one lives, the data model, and the decisions that are load-bearing.

---

## 1. The one thing to understand first

**Availability is computed, never stored.** There is no `slots` table. There is
no grid of pre-generated appointment openings that get marked taken. A slot is
free if and only if, at the instant you ask, a qualified therapist and an
equipped room both have a gap in their blocked ranges.

Everything else follows from that. If you find yourself wanting to materialise
availability into rows for performance, read section 8 first - it will break the
concurrency guarantee, and there are cheaper options.

**The blocked range is not the appointment.** A 60 minute facial at 14:00 is an
appointment from 14:00 to 15:00. But it blocks its *room* from 14:00 to 15:15
(cleanup), and blocks its *therapist* from 14:00 to 15:00 or 15:15 depending on
her personal buffer. Those two windows are stored on the booking row as
`room_release_at` and `therapist_release_at`, and they are what every overlap
comparison uses. Comparing `starts_at`/`ends_at` anywhere is a bug.

---

## 2. The rules

Thirteen rules. The README maps each to the exact file and line.

| # | Rule | Enforced where |
|---|---|---|
| 1 | A room holds one client at a time, including its cleanup period | Postgres exclusion constraint + availability filter |
| 2 | A therapist is in one place at a time, including her own buffer | Postgres exclusion constraint + availability filter |
| 3 | A client cannot hold two overlapping bookings | Postgres exclusion constraint |
| 4 | Start times are on the hour or half hour, inside opening hours, on a day the branch trades, not in the past, within the 60 day horizon, and the treatment must end by closing time | Application (`AvailabilityService`, `BookingService::assertSlotIsLegal`) |
| 5 | The therapist must work at that branch and be qualified for that treatment | Application (query filter) |
| 6 | The room must be equipped for that treatment | Application (query filter) |
| 7 | Non-members pay a deposit; members do not. A booking is confirmed only once the deposit is paid, or immediately for a member | Application (`BookingService::hold`) |
| 8 | An unpaid hold expires after 10 minutes and releases its slot, with no scheduled job | Application (`HoldSweeper`), lazy |
| 9 | A deposit is charged at most once per booking | Application + two unique indexes |
| 10 | Cancellation is free 24 hours or more ahead; inside that, the deposit is forfeited | Application (`Booking::qualifiesForRefund`) |
| 11 | A laser treatment cannot be booked without a consent record; the ID number is encrypted at rest | Application + model cast |
| 12 | A phone number must be reachable, and is stored in exactly one canonical E.164 form | Application (`E164Phone` rule + `Client` mutator), backed by `clients.phone` unique |
| 13 | Nothing is deleted. A branch, treatment or therapist is deactivated, stops being offered, and keeps its history | Application (`active` flag, checked in `AvailabilityService::day` and `BookingService::assertSlotIsLegal`) |

### Why rules 1, 2, 3 and 9 are in the database and the rest are not

This is the central design decision, so it is worth stating the criterion
plainly: **a rule goes in the database when two simultaneous requests can each
satisfy it individually while jointly violating it.**

Rule 1 is exactly that shape. Two receptionists both check 3pm, both see it
free, both write. Each request was individually correct. The pair is not. No
amount of validation in PHP fixes this, because the validation runs before the
write and the world changes in between.

Rule 4 is not that shape. Whether 14:20 is on the half hour grid does not depend
on what anyone else is doing. It is a pure function of the request, so the
application is the right place for it and a constraint would add nothing.

Rule 9 is the payment version of rule 1: two clicks, two requests, each seeing
an unpaid booking.

Rule 12 sits slightly apart from that test, and deliberately so. It is not a
concurrency rule; it is an *identity* rule. A client is identified by phone
number — `clients.phone` is unique, and both the website and reception match
returning clients with `Client::firstOrCreate(['phone' => ...])`. That only
works if the stored form is canonical. Left as typed, "085-555-5555",
"+66 85 555 5555" and "0855555555" are three rows for one woman, her membership
follows whichever one she used that day, and rule 3 stops protecting her because
it compares `client_id`. So normalisation happens in a model mutator rather than
in a controller: every path in — website, reception, seeder, tinker — lands on
the same row.

`App\Support\PhoneNumber` validates the Thai numbering plan properly (mobile is
nine digits beginning 6, 8 or 9; landline is eight beginning 2–7) and accepts any
well-formed E.164 elsewhere. It does not pretend to know other countries' plans.
If Lumina ever takes meaningful international traffic, replace it with
libphonenumber — `PhoneNumberTest` documents the contract it would have to meet.

Rule 13 exists because of what the other rules store. A branch owns bookings, a
treatment owns consent records, a therapist owns a history a client may ask
about. A hard delete either breaks a foreign key or destroys records that rules
9 and 11 exist to keep, so there is no destroy route anywhere in the staff area.

### The exclusion constraints

```sql
ALTER TABLE bookings
ADD CONSTRAINT bookings_no_room_overlap
EXCLUDE USING gist (
    room_id WITH =,
    tstzrange(starts_at, room_release_at, '[)') WITH &&
)
WHERE (status IN ('pending_payment', 'confirmed'));
```

Read it as: no two rows may share a `room_id` *and* have overlapping time
ranges, considering only rows in a blocking status.

Three details that matter if you touch this:

- **`'[)'` is half-open.** The range includes its start and excludes its end, so
  a treatment may begin at exactly the instant a previous block ends. Change it
  to `'[]'` and every back-to-back booking in the clinic breaks.
- **The `WHERE` clause is the release mechanism.** A cancelled or expired
  booking falls outside it and stops blocking, which is how both cancellation
  and hold expiry free a slot without deleting anything. Audit history is
  retained.
- **`btree_gist` is required** for the `room_id WITH =` part - a plain integer
  cannot sit in a GiST index alongside a range without it. It is a standard
  contrib module, created in the same migration.

The expression `tstzrange(starts_at, room_release_at, '[)')` is immutable, which
is why it is legal in a constraint. This was verified against Postgres 16 before
the rest of the system was built.

**This is why the project is on Postgres.** MySQL has no exclusion constraints.
A port would mean `SELECT ... FOR UPDATE` over a lock row per room per day, or
serialising all writes. Both are worse. If someone proposes moving to MySQL,
this is the cost.

---

## 3. Data model

```
branches ──┬── rooms ──── room_treatment ──── treatments
           └── therapists ── therapist_treatment ──┘

clients ──── bookings ──┬── payments
                        └── consents
```

**`branches`** - opening hours as `opens_at`/`closes_at` plus `open_weekdays`
(JSON array of ISO weekday numbers). Timezone per branch; everything is
`Asia/Bangkok` today but Phuket is a separate branch and this costs nothing now.
`active` (rule 13) takes a branch off the menu without touching its history.

**`treatments`** - duration in minutes, price, `requires_consent`, and `active`.
The schema does not assume 30/60/90; the *staff form* restricts new treatments to
those three, because a duration that is not a multiple of `slot_step_minutes`
pushes every following start off the grid. Widen the rule in
`TreatmentRequest`, not the column, if that ever changes.

**`rooms`** - `cleanup_minutes`, default 15. Per-room, not a global constant,
because the laser suite may one day need longer. Linked to the treatments it can
host via `room_treatment`. Rooms are the one catalogue table with no management
screen yet; they are still seeded. See the README's "Not done".

**`therapists`** - `buffer_minutes`, default 15, **set to 0 for the senior
facialist**. This column is the reason the founder's two statements coexist.
Linked to what she is qualified for via `therapist_treatment`.

**`clients`** - matched on phone number, which is how reception already
identifies people. `phone` is unique and is stored in canonical E.164 by a model
mutator (rule 12) - never write to it bypassing Eloquent, or the matching stops
working. `is_member` lives here, so membership follows the client and the
deposit waiver is automatic.

**`bookings`** - the core table.

| Column | Note |
|---|---|
| `reference` | `LUM-XXXXXX`, what the client quotes on the phone |
| `status` | `pending_payment` / `confirmed` / `cancelled` / `expired` / `completed` |
| `starts_at`, `ends_at` | the appointment itself |
| `room_release_at`, `therapist_release_at` | **the blocked windows** - what the constraints compare |
| `room_cleanup_minutes`, `therapist_buffer_minutes` | snapshots, see below |
| `hold_expires_at` | set while unpaid, null once confirmed |
| `deposit_required`, `deposit_minor_units`, `deposit_paid_at`, `deposit_forfeited` | |
| `created_via` | `online` or `reception` |

The buffer snapshots are deliberate. If the senior facialist's buffer changes
from 0 to 15 next year, bookings taken today must not silently shift their
blocked ranges - which would either create phantom conflicts or, worse, release
time that is actually occupied. The booking records the buffers that applied
when it was made.

**Money is integers.** `deposit_minor_units` is satang. No floats anywhere near
a currency value.

**`payments`** - one row per attempt. `idempotency_key` is unique. A partial
unique index `payments_one_success_per_booking` allows at most one `succeeded`
row per booking. A refund flips the status to `refunded`, which incidentally
frees that index, so a re-booking can be paid again.

**`consents`** - own table, one per booking, only created for treatments that
require it. `national_id` uses Laravel's `encrypted` cast, so the column holds
ciphertext. Separated from `clients` on purpose: identity data should not ride
along with every ordinary booking lookup, and it is trivially deletable on a
PDPA request. See the proposal for the recommendation that Lumina not store it
at all.

**A note on `APP_KEY`.** The consent encryption is tied to it. Rotating or
regenerating `APP_KEY` makes existing consent records unreadable. On Railway it
must be set as a persistent variable, not generated at boot.

---

## 4. The availability algorithm

`AvailabilityService::day()` - for one branch, treatment and date:

1. Sweep expired holds (section 5).
2. Return empty immediately if the branch does not trade that weekday.
3. Load eligible therapists (branch + active + qualified, optionally filtered to
   one) and eligible rooms (branch + active + equipped). Empty either → no slots.
4. Load every blocking booking in a window around that day - **one query**, not
   one per slot.
5. Walk the grid from opening time in 30 minute steps while
   `start + duration <= closing time`. Skip anything in the past.
6. For each candidate, filter the in-memory therapist and room collections by
   overlap against the loaded bookings. A slot survives if at least one of each
   remains.

Cost is one query for therapists, one for rooms, one for bookings, then pure
computation. A day has at most ~20 candidate starts.

The overlap test is `aStart < bEnd && bStart < aEnd` - half-open, matching the
`'[)'` in the constraint. **If you change one, change the other.** A mismatch
means the application offers slots the database then refuses, which surfaces as
mysterious 409s.

`freeResources()` is shared between listing a day and booking a specific time,
so there is one implementation of "is this free", not two that can drift.

---

## 5. Holds without a scheduler

Constraint: *"nothing can run in the background. No scheduled jobs."*

A hold must expire. Normally that is a cron sweep. Instead, `HoldSweeper::sweep()`
runs on the request path - at the start of every availability read and inside
every booking transaction:

```sql
UPDATE bookings SET status = 'expired'
WHERE status = 'pending_payment' AND hold_expires_at <= now();
```

Expired rows fall outside the exclusion constraints' `WHERE` clause, so the slot
is instantly free.

Why not evaluate expiry at read time instead, leaving the status alone? Because
the constraint's predicate must be immutable and cannot call `now()`. A hold
that merely *looked* expired to the application would still block writes at the
database level. The status has to actually change.

Cost is one indexed `UPDATE` per availability check, against the partial index
`bookings_live_holds_idx` which contains only live holds. In the normal case it
matches nothing.

Trade-off, stated plainly: a slot held by an abandoned checkout is released the
next time *anyone* asks about that day. During trading hours that is continuous.
At 3am a slot may sit expired-but-unswept for hours, which is harmless - the
first visitor of the morning sweeps it before seeing anything.

---

## 6. Idempotent deposits

*"Sometimes the page hangs and people pay twice."* Three layers:

1. **Same key replay.** The client generates one `Idempotency-Key` per checkout
   (once, when the page loads - not per click). A payment already stored under
   that key short-circuits: the original response is returned, the gateway is
   never called.
2. **Already-paid check.** Before charging, look for an existing `succeeded`
   payment on the booking. Catches two tabs with two different keys.
3. **Unique indexes.** `payments.idempotency_key` and
   `payments_one_success_per_booking`. If two requests pass layer 2
   simultaneously, one loses on insert; the loser catches the `23505`, finds the
   winner's payment and returns it. Nobody is charged twice.

Layer 3 is the only one that is true under concurrency. Layers 1 and 2 exist to
avoid burning a gateway round trip on the common cases.

**Ordering inside the transaction:** the payment row is written *before* the
gateway call. If the process dies mid-charge, there is a record to reconcile
rather than a silent charge. If the gateway rejects, the transaction rolls back
and the client can retry cleanly.

**Known gap, worth naming.** If the gateway succeeds but the response is lost,
the transaction rolls back and we have no record of money that did move. Fixing
this properly needs a reconciliation job against the provider - which needs
background processing, which the hosting cannot do. This is in the README under
"Not done" and should be revisited when reminders force a hosting change.

---

## 7. Booking under contention

`BookingService::hold()` wraps its read and write in one transaction, then
catches `QueryException`:

- **`bookings_no_client_overlap`** → give up immediately. This client already
  has a booking then; a different room will not help.
- **room or therapist overlap** → re-read, exclude the resource just lost, and
  retry, up to 3 attempts.

That retry is the useful case. A branch has two facial rooms. Two clients book
2pm simultaneously; one loses on Room 1 and, rather than being told "gone", is
transparently placed in Room 2. Only when nothing is left does the caller get a
409.

The API distinguishes deliberately:

- **422** - the request is wrong (off grid, outside hours, unqualified
  therapist, missing consent, client already booked)
- **409** - the request was fine and lost a race; retrying at a different time
  will work

---

## 8. Things that will bite you

**Do not cache availability** without invalidating on every write. A stale slot
list means clients pick times that the database then refuses. The system is
honest under load today; a naive cache makes it dishonest.

**Do not materialise slots into rows.** Pre-generating rows and marking them
taken reintroduces the check-then-write race unless every read locks, and it
makes changing a therapist's buffer a data migration instead of an update.

**Timezones — read this one before you touch a date.** Everything is
`timestamptz`. Branch-local reasoning (grid alignment, opening hours) happens in
the branch timezone; storage and comparison are absolute. Never hand the
database a wall clock.

Laravel will do exactly that if you let it. Its default date format is
`Y-m-d H:i:s`, used both for query bindings (`Connection::prepareBindings`) and,
through `HasAttributes::getDateFormat`, for every Eloquent date attribute. A
`CarbonImmutable` carrying `+07:00` is *formatted*, not converted: the offset is
dropped and Postgres reads the result in the session timezone. A 14:00 Bangkok
booking becomes 14:00 UTC.

This bug is nastier than it sounds because the symptom depends on the machine.
On a UTC server bookings land seven hours late and block nothing, so every slot
stays on sale. On a laptop set to `Asia/Rangoon` they land thirty minutes late
and only the tail of the day goes missing. Same defect, two completely different
failing tests.

The fix is central and there is no reason to work around it per query:
`App\Database\PostgresTimestampTzGrammar` returns `Y-m-d H:i:sP`, and
`AppServiceProvider` installs it on every `pgsql` connection as it is
established. Both bindings and attributes then carry the offset, whatever the
server's timezone is set to. If you ever see a time that is a whole number of
hours out, check that this grammar is still installed before you check anything
else.

**An exclusion constraint blocks; it does not reject.** When a transaction
inserts a row that conflicts with an *uncommitted* row from another transaction,
Postgres does not raise `23P01` — it makes the second writer wait until the first
commits or rolls back, and only then decides. This is correct and it is what
makes the rule safe. It also means you cannot stage a race inside one process by
opening two connections, writing on both and committing afterwards: the process
parks on a lock only it could release, and the suite hangs instead of failing.
`DoubleBookingTest` orders it the other way round — the losing writer *reads*
during the race and writes after the winner commits — and the twenty-process
test does the real thing with `pcntl_fork`.

**`RefreshDatabase` hides concurrency.** It wraps each test in a transaction, so
a second connection sees nothing. The concurrency tests use `DatabaseMigrations`
for this reason. If you "tidy" them onto `RefreshDatabase`, they will pass while
testing nothing.

**Tests run on Postgres, not SQLite.** SQLite has no exclusion constraints. A
SQLite suite would be green while the most important rule in the system was
absent.

---

## 9. API

Public:

```
GET  /api/branches
GET  /api/treatments?branch_id=
GET  /api/therapists?branch_id=&treatment_id=
GET  /api/availability?branch_id=&treatment_id=&date=&therapist_id=
POST /api/bookings
GET  /api/bookings/{reference}
POST /api/bookings/{reference}/deposit      (requires Idempotency-Key header)
POST /api/bookings/{reference}/cancel
```

Reception, behind the `staff` middleware:

```
GET  /api/staff/diary?branch_id=&date=
POST /api/staff/bookings
POST /api/staff/bookings/{reference}/cancel
```

Reception bookings run the identical code path as public ones - same rules, same
constraints - with `created_via = 'reception'` and the option to confirm
immediately when the deposit is taken at the desk.

### Staff screens

The same data, server-rendered, behind the same middleware:

```
GET  /staff/login                      POST /staff/login     POST /staff/logout
GET  /staff/diary                      POST /staff/diary/bookings
                                       POST /staff/diary/bookings/{booking}/cancel
GET  /staff/branches      /new  /{branch}/edit      PUT /{branch}   PATCH /{branch}/toggle
GET  /staff/treatments    /new  /{treatment}/edit   PUT /...        PATCH /.../toggle
GET  /staff/therapists    /new  /{therapist}/edit   PUT /...        PATCH /.../toggle
```

Note what is absent: there is no `DELETE` anywhere. `toggle` flips `active`.
See rule 13.

### Authentication, and what it deliberately is not

One shared credential, presented either as a session (set by the login page, so
staff can click between screens) or as `Authorization: Bearer` (so the JSON API
and the race scripts are unchanged). `StaffToken` accepts both, compares with
`hash_equals`, and answers a browser with a redirect and an API client with a
401 rather than a 302 to an HTML page. The login route is throttled; the session
id is regenerated on success.

What it is not is a user system. There are no accounts, no roles, and no record
of *who* did anything — only whether a booking arrived from `reception` or
online. The token is branch-blind: one string opens all six diaries.

That is a scoping decision, not an oversight, and it is conditional. The diary
shows client names and phone numbers, and `consents` holds national ID numbers.
**If phase one goes live holding consent records, per-person logins with a
branch role are a launch condition rather than a phase-two item.** If the
proposal's recommendation is accepted — store that consent was given and on what
date, not the number itself — the shared token is proportionate for six weeks.
Either way, the decision belongs to Lumina and should be taken at kickoff rather
than discovered later.

---

## 10. If you change one thing, change its pair

Sorted by how quietly they fail:

| If you change | You must also change |
|---|---|
| `Booking::BLOCKING` | the `WHERE` clause on all three exclusion constraints |
| the `'[)'` bound in a constraint | `AvailabilityService::overlaps()` |
| how `room_release_at` is computed | the constraint that compares it |
| `slot_step_minutes` | nothing - but re-check every duration still fits the grid |
| the `payments` unique indexes | `DepositService::isDuplicate()` |
| the query grammar on the `pgsql` connection | nothing - but if you remove `PostgresTimestampTzGrammar`, every stored time silently shifts by the server's UTC offset |
| how a phone number is normalised | nothing in code, but existing `clients.phone` rows need a backfill, or returning clients stop matching |

The first two are the dangerous ones: they fail silently, in opposite
directions. A mismatch either offers slots the database refuses, or - worse -
lets two bookings into one room.

The grammar row is the one that has already bitten this codebase once. See
section 8.
