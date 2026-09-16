# Online booking for Lumina Clinics

**Phase one proposal**
Prepared for the founder, ahead of our kickoff meeting.

---

## What we heard

You want clients to book online instead of phoning or messaging the branch. You
saw a competitor's site and want the same, but nicer.

Underneath that, the call surfaced something more specific. The thing that hurts
is not that you lack a booking page. It is that six paper diaries cannot be
trusted. Two clients turned up for the same 3pm laser slot last Christmas. The
deposit link sometimes charges people twice and your team refunds by hand. Your
best facialist's time is allocated by whoever is holding the pen.

So phase one is not really a website. It is a single reliable diary, with a
booking page attached to it. We think that is the right order, and this proposal
explains why.

---

## What we will build

**One shared diary across all six branches.** Every booking - online, phone or
WhatsApp - lands in the same place. Both receptionists at a busy branch see the
same screen at the same time, and it updates while they watch.

**A booking page for clients.** They pick a treatment, a branch, a date and a
time. If they always see the same therapist, they can pick her and only see her
free times. They pay the deposit and get a reference.

**Your menu, your branches, your team - editable by you.** Prices change, a
therapist joins, a branch changes its Sunday hours, a treatment is retired. None
of that should need a phone call to us. The reception area includes screens for
branches, treatments and therapists, and the settings that matter are the ones
the rules actually use: a branch's opening hours and trading days, a treatment's
duration and whether it needs a consent form, and each therapist's own
turnaround and what she is trained on.

One deliberate omission: nothing in those screens deletes. A branch that closes
or a treatment you stop offering is switched off, not removed, so last year's
appointments, deposits and consent records stay intact and auditable. If you
need something genuinely erased - a client exercising a PDPA right, for example
- that is a separate, deliberate action, not a side effect of tidying up the
menu.

**Rules that hold, not rules that usually hold.** This is where most of the
engineering goes, and it is worth being precise about what we mean.

### The 3pm laser slot cannot happen again

The obvious way to prevent a double booking is to check whether the slot is free
before saving it. That check is not enough, and the Christmas incident is
exactly why. When two receptionists are working the same desk, both can look at
the same moment, both see 3pm is free, and both write. Each was telling the
truth when she looked.

We are enforcing it one level lower, inside the database itself. The database
holds a rule that says *this room cannot contain two overlapping appointments*,
and it will physically refuse to store the second one. Not "check and then
save". The storage layer itself will not accept it.

We have already tested this. Two people booking the identical slot at the
identical instant: one succeeds, the other is told immediately that it has just
gone, and is shown the next free time. Twenty people at once gives the same
result. One booking.

The same rule covers your therapists. Nobody can be in two rooms at once.

### The double payment stops

Right now, if the payment page hangs and the client presses pay again, they get
charged twice and someone refunds it manually.

Every payment attempt will carry a unique ticket. If the same attempt arrives
twice - because the page hung, because they pressed the button again, because
their phone retried on a bad connection - the system recognises the ticket it
has already seen and returns the original result. It does not charge again. If
two different attempts somehow race each other, the database enforces that a
booking can only have one successful deposit.

We think this is worth calling out because it is a quiet, ongoing cost to you
today: staff time, refund fees, and a client whose first experience of Lumina
was being charged twice.

### Time is handled the way your clinic actually works

A room needs 15 minutes after each client, so a 60 minute facial at 2pm does not
free that room until 3:15. Your senior facialist needs no break at all - she
preps the next client while the laser cools. The system treats the room and the
therapist as two separate things with two separate timings, which is what lets
both of those be true at once. She can go straight from one client to the next,
as long as there is a second room for her to walk into.

That distinction sounds small. It is the difference between a system that
matches how your clinic runs and one your team quietly works around.

---

## Two things we want to flag before we start

### The ID numbers

You asked the system to keep the client's ID number and date of birth from the
laser consent form.

We can do this, and we have built it so that the ID number is encrypted - if
anyone ever got a copy of the database, they would get unreadable text rather
than a list of your clients' identity numbers. It is stored separately from the
booking, and only for laser treatments that legally need it.

But we would like to discuss at kickoff whether the system should hold it at
all. Holding national ID numbers raises your obligations under Thailand's PDPA
and makes Lumina a more attractive target. The alternative is that the consent
form stays on paper at the clinic, or that the system records only *that*
consent was given and on what date, without the number itself.

This is your call and your legal exposure, not ours. We simply do not want to
store it by default without you having chosen to.

### The hosting cannot run background tasks

Your IT contact is right that nothing can run in the background on the current
shared hosting. That genuinely constrains the design, and we have worked within
it rather than asking you to change hosts mid-project.

The main consequence you will notice: when a client starts booking and does not
pay, we hold their slot for 10 minutes and then release it. Normally that
release is handled by a background task. Ours happens the next time anyone looks
at that day's availability, which is effectively continuous during trading
hours.

The consequence you will not notice, but should know about: automatic SMS or
email reminders the day before an appointment need something running in the
background. They are therefore not in phase one. This is the single biggest
thing the hosting constraint costs you, and we would recommend it as the first
item in phase two, along with a small hosting change to support it.

---

## About your receptionists

They were in the room, and they are worried this replaces them. We would like to
address that directly, because it also affects whether the project succeeds.

It does not replace them, and the system is built on that assumption. Clients
will keep phoning and messaging - especially older clients, first-time clients,
and anyone asking which treatment they need. Those bookings go into the same
diary, entered by the receptionist, following exactly the same rules. She can
confirm a booking when someone pays at the desk. She can cancel and rebook.

What changes is which parts of her day are worth her attention. She stops being
the mechanism that prevents double bookings, because she is not reliable at that
and it is unfair to ask her to be. She stops processing manual refunds for
double charges. She stops phoning another branch to ask what is in their diary.
She stops writing the same client's number down three different ways and ending
up with three versions of the same person: the system settles on one form of a
phone number, so a regular is recognised whether she typed 085-555-5555 or
+66 85 555 5555.

In our experience, a team that was consulted during the build uses the system
and tells you when it is wrong. A team that has it delivered to them works
around it, and the diary quietly goes back on paper. We would like 30 minutes
with one receptionist from a busy branch during week two.

---

## What is not in phase one

Being explicit, so there are no surprises at handover:

- **Automatic reminders** (SMS or email before the appointment) - needs
  background processing, see above
- **Rescheduling by the client** - they can cancel and rebook; moving a booking
  in place comes later
- **Memberships and packages** - the system knows who is a member and waives
  their deposit, but does not sell or track memberships or prepaid courses
- **Reporting and analytics** - no revenue or utilisation dashboards yet
- **Staff rotas and holidays** - the system currently assumes therapists work
  the branch's opening hours; individual shifts and days off come next
- **Individual staff logins** - the reception area is behind a single shared
  password in phase one, which is not good enough long term

That last one deserves more than a bullet, because it is a decision for you
rather than for us.

One shared password means two things. The first is that you cannot tell who did
what: every booking taken at the desk is recorded as "reception", not as Nok or
Ploy. The second matters more. That one password opens the diary at all six
branches, and the diary shows your clients' names and phone numbers. If the
system is also holding the ID numbers and dates of birth from the laser consent
form, that same password is standing in front of the most sensitive data Lumina
has, and everybody at every branch knows it.

So our recommendation is conditional, and the condition is the one on the
previous page:

- **If you accept our recommendation not to store ID numbers** - we record that
  consent was given and on what date, and the paper form stays in the branch -
  then a shared password is proportionate for six weeks, and per-person logins
  are early phase two.
- **If Lumina wants the ID numbers in the system**, then per-person logins with
  a role per branch become a *launch condition*, not a phase-two item. We would
  rather build them in week five than explain a shared password to a regulator.

Either way this is a ten-minute conversation at kickoff, and it is much cheaper
to have it now than after the data is in.

---

## Timeline

Six weeks, fixed, live at the end. The sequence:

| Weeks | What happens |
|---|---|
| 1 | Confirm the details below. Build the diary, the rules, and the tests around them. |
| 2 | Booking flow and deposits end to end. Session with a receptionist. |
| 3 | Reception diary in the branches' hands. Your real treatment list and staff loaded in. |
| 4 | You and your team use it against real availability. We fix what that surfaces. |
| 5 | One branch takes real bookings, paper diary still running alongside as a safety net. |
| 6 | All six branches. Paper diary retired. |

Weeks 4 and 5 are deliberately unglamorous. A booking system that is 95% right
is worse than a paper diary, because people trust it. The staggered rollout is
how we avoid finding a problem in all six branches at once.

---

## What we need from you

To start on time, at or before kickoff:

1. **The treatment list.** Every treatment, its exact duration, its price, and
   whether it needs consent.
2. **Staff, by branch.** Who works where, what each is qualified to perform, and
   who else besides your senior facialist needs no break between clients.
3. **Rooms, by branch.** How many, and which treatments each is equipped for -
   specifically which rooms have the laser.
4. **Opening hours per branch,** including any branch that differs or closes on
   a particular day.
5. **The payment account.** Which provider processes the deposits, and access to
   connect it.
6. **A decision on the ID numbers** (see above).

The first four are the ones that slip. If the treatment list is a week late, the
rollout moves a week, because everything is tested against real durations.

---

## In short

You asked for the competitor's site, but nicer. We think what you actually need
first is a diary your six branches can share and your team can trust, with a
booking page on the front of it. That is what this phase delivers, and the
design work has gone into the two failures that cost you money today: the double
booking and the double charge.

The nicer part - the polish, the reminders, the packages - is worth doing, and
it is worth doing second, on top of something that is correct.

We look forward to the kickoff.
