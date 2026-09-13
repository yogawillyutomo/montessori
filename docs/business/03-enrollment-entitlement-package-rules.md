# Montessori Bloom — Enrollment, Session Entitlement & Package Rules

Version: **v0.1 baseline**  
Status: **Business baseline / pre-implementation**

## 1. Purpose

This document defines the relationship between:

- program / level;
- session plan;
- child enrollment;
- monthly entitlement;
- session credits;
- recurring schedule;
- booking;
- reschedule;
- makeup;
- attendance outcome;
- plan changes;
- entitlement adjustments;
- billing boundary.

## 2. Core model

```text
PROGRAM / LEVEL
      -> SESSION PLAN
      -> CHILD ENROLLMENT
      -> PERIOD ENTITLEMENT
      -> SESSION CREDIT
      -> BOOKING
      -> RESCHEDULE / MAKEUP
      -> ATTENDANCE / OUTCOME
```

Each layer has a different meaning.

## 3. Program/level must not hardcode session quota

Do not implement rules such as:

```text
if level == Infant then sessions_per_month = 8
if level == Glow then sessions_per_month = 4
```

Instead:

```text
Program / Level
      -> Default Session Plan
```

Examples:

```text
Infant -> Infant Regular 8
Glow   -> Glow Regular 4
```

The same level may later support multiple plans.

## 4. Session plan

A Session Plan defines service entitlement, not pedagogy.

Example:

```text
Plan Name: Infant Regular 8
Entitlement: 8 sessions
Period: Monthly
Preferred Frequency: 2x/week
Makeup: Allowed
Rollover: Off
```

Another example:

```text
Plan Name: Glow Regular 4
Entitlement: 4 sessions
Period: Monthly
Preferred Frequency: 1x/week
Makeup: Allowed
Rollover: Off
```

Session count must remain configurable. Future plans may use 6x, 12x, term-based or custom quotas.

## 5. Session Plan and Billing Plan are different

Session Plan answers:

> How many sessions is the child entitled to?

Billing answers:

> How much must be paid?

Do not merge these concepts.

A price change must not rewrite historical entitlement.

## 6. Child enrollment

A child receives a plan through enrollment or a plan assignment attached to enrollment.

Example:

```text
Child: Alya
Program: Infant
Plan: Infant Regular 8
Enrollment Start: 1 Sep 2026
```

Plan assignment must preserve history through effective dates.

## 7. Effective-dated plan assignment

Example:

```text
Infant Regular 8
1 Jul -> 30 Sep

Infant Regular 12
1 Oct -> onward
```

Do not overwrite the previous plan and lose historical meaning.

## 8. Default plan and individual override

A program may have a default plan while a specific child uses another plan.

Example:

```text
Glow default: 4 sessions/month
Nala override: 8 sessions/month
```

A different plan must not require creation of a fake new class or level.

Overrides should require authorized staff and audit history.

## 9. Period entitlement

For a monthly plan, each period creates a concrete entitlement.

Example:

```text
Alya
September 2026
Plan: Infant Regular 8
Base Entitlement: 8 sessions
```

This record is the entitlement for that period, not merely a property on the child.

## 10. Monthly entitlement is not four weeks

Important:

```text
8 sessions/month != 2 sessions/week * 4
```

A month can contain five occurrences of a weekday.

Example:

```text
Recurring: Monday + Thursday
Potential occurrences this month: 10
Entitlement: 8
```

The system must detect the difference.

## 11. Preferred frequency vs entitlement

Preferred frequency helps scheduling.

Example:

```text
Preferred Frequency: 2x/week
Entitlement: 8/month
```

The entitlement remains the hard service boundary. Recurrence alone must not generate unlimited valid bookings.

## 12. Recurrence exceeding entitlement

Example:

```text
Glow 4
Recurring every Friday
Month contains 5 Fridays
```

The system should warn:

```text
Potential recurring occurrences: 5
Entitlement: 4
```

Do not silently grant a fifth ordinary entitlement.

## 13. Session credit

A period entitlement may be represented as concrete session credits.

Example:

```text
September entitlement: 8
Credit #1
Credit #2
...
Credit #8
```

A credit represents one service right.

## 14. Credit is not booking

A single credit may move across bookings through reschedule.

```text
Credit #3
 -> Original Booking: 14 Sep Session 1
 -> Reschedule
 -> Destination Booking: 16 Sep Session 3
```

The credit remains one entitlement.

## 15. Reschedule uses the same credit

A move does not add quota.

```text
Original booking -> destination booking
Credit consumed/allocated: still 1
```

This avoids accidental 9/8 or 5/4 entitlement totals.

## 16. Makeup normally uses the same credit

When makeup replaces an eligible missed session:

```text
Credit #4
 -> Original opportunity
 -> Eligible missed outcome
 -> Makeup booking
 -> Final attendance
```

Makeup should not silently create an extra package entitlement.

## 17. Credit lifecycle

Recommended conceptual states:

```text
AVAILABLE
BOOKED
USED
FORFEITED
```

Movement and makeup relationships should carry context instead of creating dozens of credit statuses.

## 18. Attendance outcome and credit consequence

The plan/policy determines how attendance outcomes affect credit.

### Present

Recommended default:

```text
PRESENT -> USED
```

### Rescheduled out

```text
RESCHEDULED_OUT -> no additional consumption
```

The same credit follows the destination booking.

### School cancellation

Recommended invariant:

```text
SESSION_CANCELLED_BY_SCHOOL -> entitlement preserved
```

A child should not lose a paid/entitled opportunity because the school cancelled it.

### Sick

Configurable policy. Recommended MVP default:

```text
SICK -> eligible for replacement/makeup
```

### Excused

Configurable. Recommended MVP default:

```text
EXCUSED -> eligible for replacement/makeup
```

### No-show / absent

Configurable. Recommended MVP default:

```text
ABSENT / NO_SHOW -> credit forfeited, no automatic makeup
```

### Unmarked

Critical invariant:

```text
UNMARKED -> no final credit settlement
```

`UNMARKED` must not be treated as used, absent or forfeited.

## 19. Delayed attendance and credit settlement

Attendance may be entered later.

Example:

```text
10 Sep: session completed, attendance UNMARKED
15 Sep: attendance corrected to PRESENT
```

Credit settlement can therefore be delayed until the actual outcome is known.

Do not expire/forfeit the credit merely because attendance was not entered promptly.

## 20. Makeup eligibility

A plan may define policy such as:

```text
Sick            -> Yes
Excused         -> Yes
No-show         -> No
School cancel   -> Yes
Family cancel   -> Manual / policy-defined
```

The exact rules are configurable business policy.

## 21. Makeup is not automatically scheduled

Eligibility should create a right/opportunity, not an automatic slot selection.

```text
Eligible
 -> Find valid available destination
 -> Authorized staff chooses slot
 -> Makeup booking created
```

## 22. Cross-month makeup

A September credit may be redeemed in October when policy allows.

Example:

```text
Credit origin: September
Missed eligible session: 28 Sep
Makeup attended: 2 Oct
```

The booking must remain related to the September-origin credit.

Do not consume October entitlement for that makeup unless the school explicitly chooses a different policy.

## 23. Makeup is not rollover

These concepts are different.

### Makeup

A specific earlier service opportunity remains redeemable due to an eligible outcome.

### Rollover

An otherwise unused ordinary credit is carried into the next period.

Rollover may be off while cross-period makeup is still allowed.

## 24. Rollover

Recommended MVP default:

```text
ROLLOVER = OFF
```

Architecture should allow later options such as:

```text
OFF
ALL
MAX_N
```

Do not add automatic rollover before school policy is confirmed.

## 25. Makeup expiry

No automatic makeup expiry for MVP unless school policy explicitly defines one.

Future possibilities:

- N days;
- until term end;
- custom date.

## 26. Child joining mid-month

Do not assume a child who joins on the 20th automatically gets either 0 or the full 8-session quota.

Recommended approach:

```text
Normal Plan: 8/month
First Period Entitlement: explicit confirmed value
Next Full Period: 8/month
```

Example:

```text
Join 20 Sep
September entitlement: 3
October onward: 8/month
```

The system may suggest a value, but authorized staff confirms it.

## 27. Do not automatically prorate by calendar days

Avoid formulas such as:

```text
8 * remaining_days / total_days
```

as the only source of truth.

The service is session-based, not continuously consumed by calendar day.

## 28. Billing proration and entitlement proration are separate

Example:

```text
First-period entitlement: 3/8 sessions
```

does not automatically mean:

```text
Price = 3/8 monthly fee
```

Billing policy may differ.

## 29. Upgrade next period

Recommended default:

```text
September: Glow 4
October: Glow 8
```

No historical September rewrite.

## 30. Mid-period upgrade

Use an entitlement adjustment rather than rewriting the original plan/entitlement.

Example:

```text
Base entitlement: 4
Adjustment: +2
Effective entitlement: 6
```

The permanent plan may still change next month.

## 31. Downgrade

Recommended default:

> Downgrades become effective next period.

This avoids contradictions such as a child already having used six sessions but suddenly being assigned a four-session historical quota.

Mid-period downgrade should be explicit and exceptional.

## 32. Entitlement adjustment

Use explicit adjustments for exceptional changes.

Examples:

```text
+1 Courtesy
+2 Purchased Extra
+2 Mid-month Upgrade
-1 Administrative Correction
```

Store:

- amount;
- reason/category;
- actor;
- timestamp;
- optional note.

Adjustment changes the effective period entitlement without pretending the underlying plan itself changed historically.

## 33. Suspension

Future-friendly model:

```text
Enrollment status: SUSPENDED
start_date
end_date
reason
```

For a fully suspended period, entitlement may be zero/not generated. Partial-period behavior should use explicit adjustment until a more specific policy exists.

## 34. Enrollment end

Ending enrollment must stop future entitlements.

If future bookings already exist beyond the end date, the system should warn and require explicit cancellation/reconciliation instead of silently deleting history.

## 35. Entitlement and capacity are separate guards

A booking requires both:

```text
Child has available entitlement
AND
Destination session has available capacity
```

A session slot being free does not mean a child has package rights to use it.

## 36. Extra sessions

Additional ordinary service should be explicit.

Examples:

- bonus/courtesy session;
- purchased extra session;
- school replacement.

Represent these through entitlement adjustment or another explicit entitlement source, not hidden overbooking.

## 37. Credit prioritization

If a child has:

```text
September makeup credit
+ October regular credits
```

and an October booking was created specifically as September makeup, the booking should reference the September credit explicitly.

Avoid ambiguous automatic credit consumption.

## 38. Booking types

Recommended MVP:

```text
REGULAR
RESCHEDULE
MAKEUP
```

Possible later:

```text
TRIAL
BONUS
SPECIAL
```

Trial should not silently consume regular package entitlement unless policy says so.

## 39. No double consumption

One credit cannot produce two attended sessions.

Forbidden example:

```text
Credit #3 -> PRESENT on 14 Sep
Credit #3 -> PRESENT again on 16 Sep
```

If the first attendance was wrong, correct it before reusing the credit.

## 40. Historical corrections and downstream impact

Attendance corrections may invalidate makeup or booking decisions.

Example:

```text
Old outcome: SICK -> makeup granted
Correction: PRESENT
```

The system should warn if the correction affects:

- makeup eligibility;
- destination booking;
- credit state;
- report metrics.

Authorized users then reconcile the dependent records deliberately.

## 41. Views by role

### Parent-facing

Use simple concepts:

```text
Package: Infant 8
Included: 8 sessions
Completed: 5
Upcoming: 2
Makeup pending: 1
```

Do not expose internal credit IDs or adjustment ledger details.

### Admin-facing

Admin may need:

```text
Base entitlement      8
Adjustments           +1
Effective entitlement 9
Used                  5
Upcoming              2
Makeup pending         1
Available             1
Forfeited             0
```

### Guide-facing

Guide should see only operational context relevant to child care/learning. Billing complexity should not dominate the guide workflow.

## 42. Recommended MVP policy defaults

Until school policy is further validated:

```text
Entitlement period: Monthly
Rollover: Off
Makeup expiry: None
School cancellation: Preserve entitlement
Reschedule: Same credit
Makeup: Same credit
Attendance deadline: None
Auto absent: Off
No-show makeup: Off
Sick makeup: On
Excused makeup: On
Mid-month enrollment: Explicit first-period entitlement
Plan downgrade: Next period by default
Plan upgrade: Next period by default, optional current-period adjustment
Custom entitlement: Authorized staff only
Max sessions per child/day: 1
```

These are configurable policy defaults, not hardcoded eternal rules.

## 43. Conceptual entities

Core:

- Program
- SessionPlan
- ChildEnrollment
- EnrollmentPlanAssignment
- EntitlementPeriod
- SessionCredit
- EntitlementAdjustment

Operational relationships:

- RecurringSchedule
- SessionOccurrence
- ChildSessionBooking
- BookingMovement
- Attendance

Future billing entities remain separate.

## 44. Key invariants

1. Program/level does not hardcode session quota.
2. Plan changes preserve history through effective dates.
3. Entitlement belongs to a concrete period.
4. One credit represents one service right.
5. Reschedule does not create additional entitlement.
6. Makeup normally continues the same credit.
7. `UNMARKED` has no final consumption outcome.
8. School cancellation must not silently forfeit entitlement.
9. A child has at most one active session per calendar day.
10. Destination booking must pass both entitlement and capacity validation.
11. Manual entitlement adjustments are auditable.
12. Billing price and service entitlement remain separate concepts.

## 45. Product principle

> **Flexible package, explicit entitlement, traceable usage.**

User-facing language should stay simple (`8 sessions/month`, `1 makeup pending`) while the internal model remains precise enough to preserve history.