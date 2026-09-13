# Montessori Bloom — Alpha to Business Baseline Gap Analysis

Version: **v1.0**  
Status: **Architecture planning baseline**  
Reviewed against `main` after business baseline merge.

## 1. Purpose

This document maps the current alpha implementation to the agreed business baseline and identifies what should be:

- retained;
- hardened;
- extended;
- migrated;
- deprecated only after safe cutover.

The goal is **not** to rewrite the application. The goal is to evolve the existing Laravel monolith safely.

## 2. Executive conclusion

The current alpha is useful and should be preserved as the migration starting point. Its strongest reusable foundations are:

- `Student`, `Guardian`, `Teacher`, `AcademicYear`, `Term`;
- `DevelopmentArea`, `Indicator`, `Observation`;
- `ClassSession` as historical operational data;
- attendance records with `marked_by` / `marked_at`;
- report records and parent visibility concept;
- role middleware, access-scoping service and authorization tests.

The largest structural gaps are:

1. current weekly schedule/session membership is not sufficient for frequent slot movement;
2. there is no first-class booking/movement history;
3. session entitlement/package rules do not exist;
4. report eligibility/maturity rules do not exist;
5. `Presentation` and longitudinal `DevelopmentProgress` do not exist as first-class domains;
6. teacher access is currently derived too heavily from mutable session/schedule relationships;
7. published report workflow is too mutable;
8. attendance storage still contains legacy semantics where `status` defaults to `present` even though `marked_at = null` means not yet marked;
9. ILP/follow-up behavior is too automatic for the agreed business model.

## 3. Current alpha model inventory

### 3.1 Core identity and school structure

Current concepts:

- users;
- school classes;
- class levels;
- guardians;
- teachers;
- students;
- academic years;
- terms.

Recommended disposition:

| Current | Disposition | Target meaning |
|---|---|---|
| `users` | KEEP + HARDEN | authenticated identity |
| `guardians` | KEEP | guardian profile |
| `teachers` | KEEP, evolve terminology | guide/adult profile |
| `students` | KEEP | child profile |
| `academic_years` | KEEP | academic period container |
| `terms` | KEEP | reporting/academic sub-period |
| `school_classes` | KEEP | administrative grouping |
| `class_levels` | KEEP / clarify | program or level configuration |

Do **not** force `school_classes` to become Montessori environments. Those are different concepts.

## 4. Environment gap

### Current state

The alpha primarily associates students with one `school_class_id`, and schedules/sessions are also class-based.

### Target state

Introduce a distinct Montessori `Environment` concept with:

- environment membership;
- guide assignment;
- optional primary/responsible guide relationship;
- independent relationship from administrative class.

### Migration recommendation

Additive only at first:

```text
school_classes          keep
students.school_class   keep

+ environments
+ environment_memberships
+ environment_guide_assignments
```

Do not remove `school_class_id` during the first migration phases.

## 5. Scheduling gap

### Current state

The alpha uses:

```text
weekly_schedules
  -> student_weekly_schedule
  -> class_sessions
  -> class_session_student
```

This is enough for basic weekly scheduling, but it does not preserve the required semantics for frequent movement of one child between slots.

### Target state

```text
SessionTemplate
  -> SessionOccurrence

Child RecurringSchedule
  -> ChildSessionBooking
  -> BookingMovement
```

The important distinction is:

```text
regular pattern
!= actual dated occurrence
!= child's allocated slot
!= movement history
!= attendance outcome
```

### Current-to-target mapping

| Alpha | Target |
|---|---|
| `weekly_schedules` | legacy source for `session_templates` and/or recurring patterns |
| `student_weekly_schedule` | legacy source for child `recurring_schedules` |
| `class_sessions` | legacy source for `session_occurrences` |
| `class_session_student` | legacy source for `child_session_bookings` |

The first implementation should backfill and run compatibility logic rather than immediately renaming/dropping these tables.

## 6. Reschedule gap

### Current state

A child is directly related to a session through the pivot table. Moving the child naturally encourages replacing the relationship.

### Target state

A movement must be represented explicitly:

```text
Booking A
  -> RESCHEDULED_OUT
  -> BookingMovement
  -> Booking B
```

Required invariant:

> Move the booking, never rewrite history.

### New concepts

- `child_session_bookings`;
- `booking_movements`;
- booking source type;
- booking status;
- movement reason;
- source/destination chain.

## 7. One-session-per-day gap

### Business invariant

```text
one child <= one active booking per calendar date
```

### Current state

The current alpha has conflict checks at application level but does not have the target booking abstraction.

### Target

Enforce this at multiple layers:

1. service/domain validation;
2. database constraint where practical;
3. regression test covering regular, reschedule and makeup bookings.

## 8. Capacity gap

### Current state

Capacity already exists on current schedule/session structures.

### Disposition

**KEEP the concept.**

### Target refinement

Capacity should be evaluated against **active bookings**, not merely pivot row count.

Statuses such as `RESCHEDULED_OUT`, `CANCELLED`, or `SESSION_CANCELLED` must not consume destination capacity.

## 9. Attendance gap

### Current state

Attendance currently has:

- session;
- student;
- `status`;
- `note`;
- `marked_by`;
- `marked_at`.

The original schema defaulted `status` to `present`; later logic uses `marked_at = null` to represent not yet marked.

### Problem

The data representation is ambiguous:

```text
status = present
marked_at = null
```

means "UNMARKED" at business level, not present.

### Target

Make the business state explicit.

Recommended target statuses:

```text
UNMARKED
PRESENT
ABSENT
SICK
EXCUSED
LATE
```

Keep:

- `recorded_by` / current `marked_by`;
- `recorded_at` / current `marked_at`;
- correction audit history.

### Critical invariant

- no automatic attendance deadline;
- completing a session does not require attendance completion;
- changing calendar day does not auto-mark absence;
- `UNMARKED != ABSENT`;
- historical correction remains possible for authorized users.

## 10. Attendance-to-booking relationship gap

### Current

Attendance is keyed by:

```text
class_session_id + student_id
```

### Target

Prefer:

```text
child_session_booking_id
```

because the booking captures whether the occurrence was regular, rescheduled or makeup.

During migration, keep legacy columns until reconciliation is complete.

## 11. Enrollment gap

### Current state

A student record has status/class relationships, but there is no effective-dated service enrollment model.

### Target

Introduce:

- `child_enrollments`;
- effective dates;
- program/level relationship;
- enrollment status;
- plan assignment history.

This enables:

- mid-month enrollment;
- suspension;
- program transfer;
- plan change without rewriting history.

## 12. Session plan and entitlement gap

### Current state

No formal session entitlement model exists.

### Target

Introduce:

- `session_plans`;
- `enrollment_plan_assignments`;
- `entitlement_periods`;
- `session_credits`;
- `entitlement_adjustments`.

Example:

```text
Infant Regular 8 -> 8 sessions/month
Glow Regular 4   -> 4 sessions/month
```

These must be configurable data, not hardcoded rules.

## 13. Weekly frequency vs monthly entitlement gap

The alpha schedule can express weekly recurrence, but the target business rule requires:

```text
preferred weekly recurrence != monthly entitlement
```

A month with five Mondays must not silently create a fifth entitled session for a 4-session plan.

The target scheduler should warn when recurrence produces more candidate occurrences than entitlement.

## 14. Makeup gap

### Current

No explicit entitlement-aware makeup model.

### Target

A makeup normally remains attached to the same session credit:

```text
September Credit #4
  -> original booking missed
  -> makeup eligible
  -> October makeup booking
  -> present
```

Cross-month makeup must not accidentally consume the next month's normal entitlement.

## 15. Montessori presentation gap

### Current

Observation can describe learning events, but `Presentation` does not exist as a first-class record.

### Target

Introduce `presentations` with at least:

- child;
- guide;
- material/activity;
- occurrence/context;
- presented date;
- optional note.

This answers:

> What has this child actually been introduced to, when, and by whom?

## 16. Montessori material/activity gap

### Current

There are development areas and indicators, plus free-text schedule topics.

### Target

Introduce a lightweight curriculum/activity layer:

- `montessori_activities` or `materials`;
- development area relationship;
- direct/indirect aims if useful;
- active flag;
- optional sequencing/prerequisite metadata.

Do not over-model the full Montessori curriculum in MVP.

## 17. Observation gap

### Current strengths

Observation is already a strong reusable domain:

- scheduled or spontaneous;
- optional session relationship;
- development area;
- indicator;
- level;
- narrative note;
- follow-up flag;
- include-in-report flag.

### Target changes

Observation should become **evidence**, not a direct report/ILP trigger.

Recommended evolution:

- optional activity/material relationship;
- explicit visibility classification if needed;
- avoid auto-creating support plans;
- preserve raw observation history;
- support objective note vs guide interpretation if the school needs that separation.

## 18. Development progress gap

### Current

Observation contains a `level`, but there is no separate longitudinal progress state.

### Target

Introduce `development_progress` as professional judgement over time.

Conceptually:

```text
Presentations
+ Observations
+ Practice evidence
+ Guide judgement
= Development Progress
```

Do not automatically mutate progress because one observation has a specific level.

## 19. ILP/follow-up gap

### Current

`ilp_plans` can be triggered from an observation, and alpha behavior can generate draft ILP automatically.

### Target

Move toward:

```text
Observation
 -> FollowUpCandidate
 -> Guide Review
 -> Support Plan if confirmed
```

Recommended migration:

- keep `ilp_plans` data;
- stop treating auto-generation as final business behavior;
- introduce `follow_up_candidates` first;
- later decide whether `ilp_plans` is renamed/reframed as `support_plans`.

## 20. Report eligibility gap

### Current

Reports are term-linked and can be generated from accumulated data without first-report maturity policy.

### Target

Introduce configurable `report_policies` supporting:

- minimum observation duration;
- minimum attended sessions;
- guide confirmation;
- optional area coverage;
- reporting frequency.

A new child may be fully enrolled and attending while still:

```text
NOT_ELIGIBLE_FOR_FIRST_REPORT
```

This must not appear as a zero score or failed report.

## 21. Report cycle gap

### Current

Reports primarily attach to `term_id`.

### Target

Introduce explicit `report_cycles` so a school can support:

- monthly;
- bimonthly;
- term-based;
- custom reporting windows.

Terms may still organize school periods, but report cadence must not be hardcoded to terms.

## 22. Report workflow gap

### Current

Report statuses exist, and publish functionality exists, but the generic save path is too permissive for a true immutable publication workflow.

### Target

Recommended workflow:

```text
DRAFT
 -> SUBMITTED_FOR_REVIEW
 -> UNDER_REVIEW
 -> REVISION_REQUESTED -> DRAFT
 -> APPROVED
 -> PUBLISHED
 -> ARCHIVED
```

Published output must be immutable.

If corrections are necessary:

```text
PUBLISHED
 -> REOPEN / REVISION
 -> REVIEW
 -> REPUBLISH
```

## 23. Report versioning gap

### Target recommendation

Separate mutable workflow from immutable publication snapshot:

```text
reports
  -> report_versions / report_publications
```

A published version should retain:

- exact narrative;
- exact summary;
- attendance snapshot/context;
- evidence window;
- published timestamp;
- publisher/reviewer identity.

## 24. Parent communication gap

### Current

Parent can see own published report.

### Disposition

**KEEP this boundary.**

### Target extension

Add later:

- curated progress highlights;
- family conference record;
- agreed follow-up summary.

Do not expose all raw observation notes by default.

## 25. Family conference gap

Introduce later as a first-class record:

- child;
- date;
- participants;
- development summary;
- parent observations from home;
- agreed actions;
- next review date;
- shareability state.

This is more useful than introducing an unstructured chat module early.

## 26. Authorization gap

### Current risk

Teacher access is currently influenced by mutable schedule/session relationships. This creates the risk that a session membership change can expand access to a child's broader records.

### Target

Authorization should be based on stable context:

```text
system role
+ environment assignment
+ child responsibility
+ limited session context
```

A one-time session interaction must not automatically grant permanent access to all historical reports, support plans or sensitive notes.

## 27. Role gap

Do not immediately create many new global roles.

Recommended direction:

```text
Global account role
+ contextual assignment
```

For example:

- administrator;
- guide/teacher;
- principal/academic lead;
- parent;

plus environment-level assignment such as:

- lead guide;
- guide;
- assistant;
- specialist.

## 28. Data-security gaps already identified

The architecture migration must also address:

- default user role must not fail open to `admin`;
- demo seeding must be safe in production;
- teacher session mutation must not expand child authorization scope;
- published report mutation must be restricted;
- login throttling should be implemented;
- one-to-one `User -> Teacher/Guardian` assumptions should be backed by database invariants if kept;
- sensitive child information requires explicit access boundaries and production data-protection controls.

## 29. Controller architecture gap

Current alpha contains large `MasterController` and `ProcessController` classes.

Do not refactor them first.

Recommended order:

1. lock business invariants with tests;
2. introduce target domain tables/services;
3. cut over behavior;
4. then split controllers by capability.

Likely future capability boundaries:

- Environment;
- Enrollment;
- Session Scheduling;
- Booking/Reschedule;
- Attendance;
- Presentation;
- Observation;
- Development Progress;
- Follow-Up;
- Reporting.

## 30. Migration philosophy

Use an expand-and-contract strategy:

```text
EXPAND
add target tables/columns

BACKFILL
copy/reconcile alpha data

COMPATIBILITY
read old + new / dual write where necessary

CUTOVER
new domain becomes source of truth

VERIFY
reconciliation + regression tests

CONTRACT
retire legacy paths only after confidence
```

No destructive rename/drop should be the first step.

## 31. Priority classification

### P0 — protect correctness/security before structural migration

- teacher child-scope escalation;
- report publication/state authorization;
- production-safe demo seeding;
- remove fail-open default admin role;
- login throttling;
- regression tests around the above.

### P1 — operational domain foundation

- environment;
- session templates/occurrences;
- recurring schedules;
- bookings and booking movements;
- explicit attendance `UNMARKED` semantics.

### P2 — enrollment and entitlement

- enrollment;
- configurable session plan;
- period entitlement;
- credit/adjustment;
- makeup linkage.

### P3 — Montessori pedagogical model

- activity/material;
- presentation;
- development progress;
- follow-up candidate.

### P4 — reporting model

- report policy;
- report cycle;
- first-report eligibility;
- immutable publication versions;
- family conference.

## 32. What should NOT be rewritten

Do not rewrite Laravel, Blade or the application as microservices.

Do not discard existing historical data.

Do not replace all models at once.

Do not introduce AI into readiness, diagnosis or report eligibility before deterministic business rules are stable.

## 33. Architecture decision

The target remains a **modular Laravel monolith**.

The business domains may be separated in code and database concepts, but deployment remains simple until scale or organizational constraints prove a need for service extraction.

## 34. Definition of success

The alpha-to-target migration is successful when the application can answer these independently and correctly:

1. What program is the child enrolled in?
2. How many sessions is the child entitled to this period?
3. When does the child normally attend?
4. Where is the child actually booked this date?
5. Was that booking regular, rescheduled or makeup?
6. What actually happened in attendance?
7. What presentations has the child received?
8. What observations support the child's progress?
9. What is the current guide-confirmed development progress?
10. Is the child eligible for a report yet?
11. What exact report version was published to the parent?

When these questions no longer depend on overloaded legacy fields, the core domain migration is complete.
