# Montessori Bloom — Scheduling, Reschedule, Cancellation & Makeup

Version: **v0.1 baseline**  
Status: **Business baseline / pre-implementation**

## 1. Purpose

This document defines rules for:

- recurring schedules;
- session occurrences;
- child bookings;
- one-time reschedules;
- permanent schedule changes;
- child cancellation;
- school cancellation;
- makeup sessions;
- capacity;
- slot availability;
- historical correction;
- auditability.

## 2. Core model

The system must distinguish:

```text
RECURRING SCHEDULE
      -> SESSION OCCURRENCE
      -> CHILD SESSION BOOKING
      -> ATTENDANCE
```

These are different records with different meanings.

- Recurring Schedule: when the child normally comes.
- Session Occurrence: a concrete session on a concrete date.
- Child Session Booking: where the child is expected for that occurrence.
- Attendance: what actually happened.

## 3. Recurring schedule

A recurring schedule represents the normal pattern.

Example:

```text
Alya
Monday   -> Session 1
Thursday -> Session 3
```

It must not be treated as attendance history.

Recurring schedules should be effective-dated:

```text
valid_from
valid_until
```

so permanent schedule changes do not rewrite old history.

## 4. Session template and occurrence

A session template defines a repeating operational slot, for example:

```text
Session 1  07:30-09:00
Session 2  09:15-10:45
...
```

Times above are examples only. Session number, time, capacity and environment must be configurable.

A template produces dated session occurrences:

```text
Monday Session 1
-> 14 Sep Session 1
-> 21 Sep Session 1
-> 28 Sep Session 1
```

One-off time changes belong to the occurrence, not the template.

## 5. Child booking

A child session booking answers:

> Where is this child expected on this date?

Example:

```text
Child: Alya
Date: 14 Sep 2026
Session: Session 1
Source: Recurring Schedule
```

Booking must be first-class because rescheduling is frequent.

## 6. Fundamental reschedule rule

Rescheduling must never rewrite history by simply changing the old booking's date/session.

Use:

```text
SOURCE BOOKING
   -> BOOKING MOVEMENT
   -> DESTINATION BOOKING
```

Example:

```text
14 Sep Session 1
RESCHEDULED_OUT
     ->
16 Sep Session 3
SCHEDULED
```

The source booking remains part of history.

## 7. One-time reschedule

A one-time move affects only one occurrence.

Example:

```text
Normal schedule: Monday Session 1
This week only: Wednesday Session 3
Next week: Monday Session 1 again
```

A one-time reschedule must not modify the recurring schedule.

## 8. Permanent schedule change

Permanent changes use effective dates.

Example:

```text
Monday Session 1
valid until 30 Sep

Wednesday Session 3
valid from 1 Oct
```

Historical occurrences remain attached to the previous schedule.

## 9. Same-day move

A child may move between sessions on the same date.

Invariant:

```text
Child + calendar date <= 1 active booking
```

After a same-day move:

```text
Session 1 -> RESCHEDULED_OUT
Session 3 -> SCHEDULED
```

There must not be two active bookings for the same child on the same date.

## 10. Different-day reschedule

Before creating the destination booking, validate:

1. destination session exists and is active;
2. destination is not cancelled;
3. capacity is available;
4. child has no active booking on destination date;
5. environment/program constraints are satisfied;
6. entitlement/credit rules are satisfied.

## 11. Attendance and reschedule

A rescheduled source booking is not an absence.

Do not convert:

```text
RESCHEDULED_OUT -> ABSENT
```

Attendance belongs to the destination occurrence actually used.

Example:

```text
14 Sep Session 1 -> RESCHEDULED_OUT
16 Sep Session 3 -> PRESENT
```

Only the second occurrence counts as attended.

## 12. Multiple reschedules

A booking may move more than once.

Preserve the chain:

```text
Booking A -> Booking B -> Booking C
```

Do not overwrite A with C.

Current active booking is the final active booking in the movement chain.

## 13. Rescheduling after attendance

If the source booking is already `PRESENT`, ordinary reschedule should be blocked because the service has already occurred.

If the attendance was incorrect, first use an attendance correction workflow, then resolve any downstream reschedule/makeup records.

## 14. Completed session does not block later correction

Because attendance can be recorded late, a completed session does not automatically make all child outcomes final.

Example:

```text
Monday session completed
Alya = UNMARKED

Wednesday:
School confirms Alya did not attend and needs an approved replacement
```

Authorized staff may correct the historical outcome and then create the appropriate movement/makeup record.

There is no time-based lock by default.

## 15. Child cancellation

Different realities should remain distinguishable:

- family-planned cancellation;
- sick;
- excused absence;
- no-show/absent;
- rescheduled out.

Do not collapse every non-presence state into `ABSENT`.

Exact entitlement consequences are defined in the entitlement/package policy.

## 16. School cancellation

If the school cancels a session occurrence:

```text
Session Occurrence -> CANCELLED
Affected bookings -> SESSION_CANCELLED
```

Children must not be marked absent because the school cancelled the service.

The school may then create replacements individually or in bulk.

## 17. Makeup

A makeup is a replacement use of an existing entitlement after a missed/affected session when policy allows it.

Conceptually:

```text
Original booking
-> eligible outcome
-> replacement booking
-> attendance
```

Makeup must not silently create an extra package entitlement. See the entitlement rules for credit handling.

## 18. Reschedule vs makeup

Both create a movement from an original expected slot to another slot, but the business reason differs.

- Reschedule: usually a planned move of an upcoming/current booking.
- Makeup: usually a replacement after the original opportunity was missed/cancelled and policy grants replacement rights.

The system should store at least:

```text
movement_type
source_booking
 destination_booking
reason_category
optional_note
changed_by
changed_at
```

## 19. Capacity

Every session occurrence may have a capacity.

Invariant:

```text
active_bookings <= capacity
```

Bookings that no longer consume capacity include, for example:

```text
RESCHEDULED_OUT
CANCELLED
SESSION_CANCELLED
```

An over-capacity exception, if ever allowed, should require elevated authorization and audit information.

## 20. Slot availability

Reschedule UI should make availability obvious.

Example:

```text
Tuesday
Session 1  FULL
Session 2  2 slots
Session 3  1 slot
Session 4  4 slots
Session 5  FULL
```

The operator should not need to inspect every session manually.

## 21. Bulk reschedule

Bulk reschedule is useful when the school cancels or moves an entire session.

The system should pre-check destination conflicts and return a result such as:

```text
7 children affected
6 can move to Tuesday Session 4
1 has a conflict
```

It must not silently force invalid moves.

Partial bulk movement must be possible when destination capacity is insufficient.

## 22. School calendar exceptions

Recurring schedules should coexist with dated exceptions such as:

- holiday;
- school closed;
- special event;
- one-off session time change.

A one-day exception must not permanently change the recurring template.

## 23. Parent reschedule requests

Parent self-service rescheduling is not required for MVP.

Recommended MVP flow:

```text
Parent communicates request
-> authorized staff chooses destination
-> system validates
-> movement is recorded
```

A later parent request workflow may support requested date/preferences without directly granting a slot.

## 24. Recommended MVP statuses

### Session Occurrence

```text
PLANNED
OPEN
COMPLETED
CANCELLED
```

### Child Booking

```text
SCHEDULED
RESCHEDULED_OUT
CANCELLED
SESSION_CANCELLED
```

### Attendance

```text
UNMARKED
PRESENT
ABSENT
SICK
EXCUSED
LATE
```

### Movement Type

```text
RESCHEDULE
MAKEUP
```

Avoid unnecessary state explosion.

## 25. Audit requirements

Every slot movement should preserve:

- child;
- source booking;
- destination booking;
- movement type;
- reason;
- actor;
- timestamp;
- optional notes.

Historical booking and movement records must remain traceable even if a booking is moved multiple times.

## 26. Key invariants

1. One child has at most one active booking per calendar day.
2. Reschedule never destroys the source booking history.
3. `RESCHEDULED_OUT` is not `ABSENT`.
4. School cancellation is not child absence.
5. One-time movement does not modify recurring schedule.
6. Permanent schedule changes are effective-dated.
7. Destination booking respects capacity.
8. Destination booking respects entitlement.
9. A `PRESENT` source must not be rescheduled without correcting the historical attendance first.
10. Completed sessions remain correctable by authorized users because attendance is not realtime-bound.

## 27. MVP requirements

### Must have

- recurring schedule;
- session occurrence;
- child booking;
- one-time reschedule;
- same-day and different-day move;
- capacity validation;
- one-session-per-child-per-day validation;
- permanent schedule change;
- source/destination history;
- child cancellation;
- school cancellation;
- makeup booking;
- delayed attendance support;
- audit trail;
- slot availability view.

### Should have

- bulk reschedule;
- movement reason categories;
- pending makeup list;
- session availability dashboard;
- calendar exceptions;
- one-off time override.

### Later

- parent self-service request;
- waitlist;
- automatic slot recommendation;
- swap workflow;
- notifications;
- utilization analytics.

## 28. Product principle

> **Flexible scheduling, immutable history.**

The application must be flexible enough to model what really happened while preserving how the schedule changed over time.

Always keep these three questions answerable:

> When was the child normally supposed to come?

> Where was the child finally scheduled?

> When did the child actually attend?