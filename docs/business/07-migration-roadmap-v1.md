# Montessori Bloom — Migration Roadmap v1

Version: **v1.0**  
Status: **Implementation sequencing baseline**

## 1. Objective

This roadmap translates the agreed business baseline and target ERD into a safe implementation order.

The migration strategy is:

```text
SECURE
  -> EXPAND
  -> BACKFILL
  -> COMPATIBILITY
  -> CUTOVER
  -> VERIFY
  -> CONTRACT
```

The goal is to avoid a big-bang rewrite and preserve current alpha data while the domain model evolves.

## 2. Non-negotiable guardrails

Before and during migration:

- no destructive rewrite of historical schedules, sessions, observations or reports;
- no direct migration that loses original booking/session meaning;
- no authorization weakening to make migration easier;
- no assumption that `UNMARKED` means absent or present;
- no hardcoded Infant/Glow entitlement values;
- no automatic ILP/support-plan decision from a single observation;
- no automatic report for children who have not met report eligibility policy;
- no published report mutation without explicit revision flow;
- no AI-driven pedagogical decision in foundational tranches.

## 3. Tranche M0 — Security & correctness guardrail

### Goal

Protect the existing alpha before changing its structure.

### Required changes

1. Prevent teacher session mutations from expanding permanent child scope.
2. Constrain teacher/session identity and class/environment mutation paths.
3. Close report status-transition bypasses.
4. Make published report content effectively immutable in current workflow until versioning lands.
5. Remove fail-open default `admin` role from schema/application assumptions.
6. Make demo seeders refuse unsafe production execution.
7. Add login throttling.
8. Add unique profile invariants if `User -> Teacher/Guardian` remains one-to-one.
9. Add focused regression tests for every security invariant.

### Exit criteria

- known horizontal child-scope escalation has a regression test and is fixed;
- teacher cannot publish/reopen/change published reports through generic update path;
- production demo seeding cannot silently create/reset predictable privileged credentials;
- login endpoint is throttled;
- security tests pass locally/CI when CI is available.

## 4. Tranche M1 — Explicit attendance semantics

### Goal

Make data model agree with business rule:

```text
UNMARKED != PRESENT != ABSENT
```

### Changes

- introduce explicit `UNMARKED` status semantics;
- remove legacy default-present ambiguity;
- preserve `marked_by` / `marked_at` as recording metadata;
- ensure session completion does not require attendance completion;
- support retroactive attendance and correction;
- add audit trail for changes.

### Backfill rule

For existing rows:

```text
marked_at IS NULL
=> UNMARKED
```

For rows with `marked_at`:

- preserve current explicit attendance status;
- flag impossible/unknown legacy combinations for reconciliation instead of silently guessing.

### Tests

- completing a session with unmarked attendance succeeds;
- next day does not auto-absent;
- delayed mark preserves original session date;
- correction records actor/time/history;
- reporting distinguishes unmarked from absent.

## 5. Tranche M2 — Environment foundation

### Goal

Separate administrative class from Montessori environment.

### Add

- `environments`;
- `environment_memberships`;
- `environment_guide_assignments`;
- optional `child_guide_responsibilities`.

### Backfill

Use current school class relationships only as **initial suggestions**.

Do not assert that every existing school class is pedagogically identical to an environment unless confirmed.

### Compatibility

Existing screens may continue reading `school_class_id` while new environment assignments are populated.

### Exit criteria

- each active child can be associated with an environment where required;
- guide access can be derived from contextual assignment rather than mutable session membership alone;
- admin class remains available independently.

## 6. Tranche M3 — Session template & occurrence foundation

### Goal

Separate recurring slot definition from dated occurrence.

### Add

- `session_templates`;
- `session_occurrences`.

### Legacy mapping

```text
weekly_schedules -> session_templates
class_sessions   -> session_occurrences
```

### Backfill principles

- preserve original dates/times;
- preserve room/capacity;
- preserve teacher/session historical links as legacy metadata if exact environment/guide mapping is not yet known;
- never mutate historical `class_sessions` merely to make them fit target semantics.

### Compatibility

For a transition period:

- new services may create occurrence rows while maintaining legacy linkage;
- UI may read through an adapter/service that can resolve legacy or target occurrence.

## 7. Tranche M4 — Child recurring schedule & booking

### Goal

Introduce the key operational abstraction that enables safe frequent slot movement.

### Add

- `recurring_schedules`;
- `child_session_bookings`.

### Legacy mapping

```text
student_weekly_schedule -> recurring_schedules
class_session_student   -> child_session_bookings
```

### Booking invariants

- one child <= one active booking per calendar date;
- active booking counts toward capacity;
- cancelled/rescheduled-out booking does not consume capacity;
- booking history is never deleted to represent a movement.

### Tests

- same child cannot have two active bookings same date;
- two historical bookings may exist if only one is active;
- capacity is computed from active bookings;
- booking can exist before attendance is marked.

## 8. Tranche M5 — Booking movement, reschedule & cancellation

### Goal

Make frequent slot movement first-class and traceable.

### Add

- `booking_movements`;
- reschedule service;
- child cancellation service;
- session cancellation service.

### Reschedule flow

```text
source booking
 -> mark RESCHEDULED_OUT
 -> create destination booking
 -> create immutable movement link
```

### Required validation

- destination occurrence exists and is not cancelled;
- destination capacity available unless authorized override;
- child has no other active booking on destination date;
- source is not already fulfilled by present attendance;
- movement cannot produce a cycle.

### Exit criteria

The system can answer separately:

- original slot;
- current slot;
- final attendance slot.

## 9. Tranche M6 — Enrollment & configurable session plan

### Goal

Separate educational program from service entitlement.

### Add

- `child_enrollments`;
- `session_plans`;
- `enrollment_plan_assignments`.

### Example data

```text
Infant Regular 8 -> 8/month
Glow Regular 4   -> 4/month
```

These are database configuration rows, never conditional code.

### Support

- child joins mid-month;
- effective-dated plan change;
- suspension;
- program transfer;
- custom plan override.

### Default plan-change behavior

- upgrade: next period by default, current-period adjustment optional;
- downgrade: next period by default.

## 10. Tranche M7 — Period entitlement, credit & adjustment

### Goal

Represent service rights explicitly.

### Add

- `entitlement_periods`;
- `session_credits`;
- `entitlement_adjustments`.

### Invariants

- monthly entitlement is not calculated as weekly frequency x 4;
- one credit normally fulfills one attended service session;
- reschedule does not create a second credit;
- makeup uses same originating credit by default;
- origin period never changes;
- adjustment is explicit/audited.

### First-period handling

For a child joining mid-month:

- system may suggest an initial entitlement;
- authorized staff confirms/overrides;
- next full period follows normal plan.

Do not auto-prorate financial billing from session entitlement.

## 11. Tranche M8 — Makeup integration

### Goal

Link absence/cancellation outcomes to service entitlement without confusing months.

### Rules

- `PRESENT` -> credit fulfilled/used;
- `RESCHEDULED_OUT` -> credit follows destination;
- school cancellation -> credit preserved;
- sick/excused -> configurable makeup policy;
- no-show -> configurable, default no makeup;
- `UNMARKED` -> no final credit outcome.

### Cross-period behavior

A September makeup attended in October still references the September credit.

`Rollover OFF` does not automatically expire legitimate pending makeup.

## 12. Tranche M9 — Montessori activity & presentation

### Goal

Introduce the missing Montessori learning event distinct from observation.

### Add

- `montessori_activities`;
- `presentations`.

### Migration

Do not attempt to infer all historical presentations from observation text.

Only backfill presentation data when source evidence is reliable.

Legacy history may remain observation-only.

### UX

Presentation entry must be fast and optional enough not to turn guides into data-entry operators.

## 13. Tranche M10 — Development progress

### Goal

Separate raw evidence from professional conclusion.

### Add

- `development_progress`.

### Rule

One observation does not automatically become a progress state.

Recommended workflow:

```text
presentation/observations
 -> guide reviews
 -> guide confirms progress state
```

Automation may summarize evidence later, but guide judgement remains authoritative.

## 14. Tranche M11 — Follow-up candidate & support flow

### Goal

Replace direct observation-to-ILP automation with reviewable escalation.

### Add

- `follow_up_candidates`.

### Flow

```text
Observation
 -> Follow-Up Candidate
 -> Guide Review
 -> Confirm / Dismiss
 -> Support Plan if confirmed
```

### Legacy `ilp_plans`

Preserve all records.

Possible migration:

- treat existing ILP as legacy support plans;
- add source metadata;
- do not delete/recreate historical plans.

## 15. Tranche M12 — Report policy & cycle

### Goal

Make reporting cadence and first-report maturity configurable.

### Add

- `report_policies`;
- `report_cycles`.

### Policy examples

```text
Infant Standard
- monthly cycle
- >= 60 observation days
- >= 8 attended sessions
- guide confirmation required
```

```text
Glow Standard
- monthly cycle
- >= 90 observation days
- >= 8 attended sessions
- guide confirmation required
```

Values are examples/configuration, not hardcoded defaults.

## 16. Tranche M13 — Report eligibility

### Goal

Prevent forced reports for newly enrolled or insufficiently observed children.

### Add

- `report_eligibilities`;
- deterministic eligibility evaluator.

### First report criteria

Possible mandatory inputs:

- observation duration;
- actual attended session count;
- guide confirmation.

Optional/advisory input:

- development-area evidence coverage.

### States

```text
NOT_ELIGIBLE
ELIGIBLE
```

with reason codes such as:

```text
MINIMUM_DURATION_NOT_MET
MINIMUM_ATTENDANCE_NOT_MET
GUIDE_CONFIRMATION_PENDING
```

### Important

`NOT_ELIGIBLE` must never become score `0`.

## 17. Tranche M14 — Report workflow hardening & immutable publication

### Goal

Complete safe report state machine.

### Workflow

```text
DRAFT
 -> SUBMITTED_FOR_REVIEW
 -> UNDER_REVIEW
 -> REVISION_REQUESTED -> DRAFT
 -> APPROVED
 -> PUBLISHED
 -> ARCHIVED
```

### Add

- `report_versions`.

### Publication rule

Publishing creates immutable version snapshot.

Correction creates a new version after authorized reopen/review.

Parent reads the published version, not mutable working draft.

## 18. Tranche M15 — Family conference

### Goal

Add structured family collaboration after core reporting is stable.

### Add

- `family_conferences`.

### Do not prioritize

- unstructured chat;
- social feed;
- realtime raw observation feed for parents.

## 19. Tranche M16 — Controller/domain refactor

### Goal

Only after behavior is protected by tests, split large alpha controllers.

Potential boundaries:

```text
EnvironmentController
EnrollmentController
SessionOccurrenceController
BookingController
AttendanceController
PresentationController
ObservationController
DevelopmentProgressController
FollowUpController
ReportController / workflow actions
```

Move business rules into services/actions/policies rather than controllers.

## 20. Tranche M17 — Legacy contraction

### Preconditions

Do not remove legacy structures until all are true:

- target tables have been backfilled;
- reconciliation report has zero unexplained mismatch;
- target paths are source of truth;
- regression tests cover migration invariants;
- production backup/rollback plan exists;
- no active code path depends on legacy relation.

### Candidate legacy retirement

Only then consider retiring/reframing:

- `weekly_schedules`;
- `student_weekly_schedule`;
- `class_sessions`;
- `class_session_student`;
- old attendance foreign-key pattern;
- direct auto-ILP workflow;
- term-only report assumptions.

Prefer deprecation before drop.

## 21. Data reconciliation requirements

Every migration tranche with backfill must produce a reconciliation check.

Examples:

### Session occurrence

```text
legacy class_sessions count
vs
mapped session_occurrences count
```

### Booking

```text
legacy class_session_student rows
vs
mapped bookings
```

### Attendance

```text
legacy marked attendance
vs
booking-linked attendance
```

### Report

```text
legacy report rows
vs
report/cycle mapping
```

Mismatch must be classified rather than silently ignored.

## 22. Rollback philosophy

Avoid relying only on database `down()` for business migration rollback.

For major tranches use:

- forward-compatible schema;
- backup/snapshot before destructive steps;
- feature flag or source-of-truth switch where useful;
- reversible cutover;
- explicit data reconciliation.

## 23. Testing strategy

Each tranche should add tests before or with behavior.

### Security tests

- teacher cannot expand child scope through session mutation;
- parent cannot see another child;
- parent cannot see draft/internal report data;
- unauthorized actor cannot publish/reopen report.

### Scheduling tests

- max one active child booking/day;
- movement history preserved;
- same credit follows reschedule;
- capacity validation;
- school cancellation is not absence.

### Attendance tests

- delayed mark;
- unmarked after session completion;
- no auto-absence;
- correction audit.

### Entitlement tests

- 4/month remains four even in a five-Friday month;
- reschedule does not create credit;
- cross-month makeup retains origin credit;
- mid-month enrollment initial entitlement override;
- plan upgrade/downgrade effective dating.

### Reporting tests

- new child cannot receive first report before required maturity;
- insufficient attendance blocks eligibility without zero score;
- guide confirmation required when policy says so;
- after first-report maturity, later cycles do not restart initial waiting period;
- published version immutable.

## 24. Recommended release grouping

The technical tranche list does not require 18 production releases.

Recommended grouping:

### Release Foundation A

- M0 security;
- M1 attendance semantics;
- M2 environment.

### Release Operations B

- M3 session occurrence;
- M4 booking;
- M5 movement/reschedule.

### Release Service Plan C

- M6 enrollment/plan;
- M7 entitlement/credit;
- M8 makeup.

### Release Pedagogy D

- M9 presentation;
- M10 development progress;
- M11 follow-up candidate.

### Release Reporting E

- M12 policy/cycle;
- M13 eligibility;
- M14 immutable reports;
- M15 family conference.

### Release Cleanup F

- M16 controller refactor;
- M17 legacy contraction.

## 25. Immediate next implementation branch

After this architecture documentation is accepted, the next code branch should be:

```text
fix/m0-security-hardening
```

Recommended scope only:

1. child-scope escalation fix;
2. report state/publication authorization fix;
3. production-safe demo seeding;
4. fail-open admin default removal strategy;
5. login throttling;
6. regression tests.

Do not mix scheduling-table migration into M0.

## 26. Definition of roadmap completion

The roadmap is complete when:

- scheduling history survives frequent slot changes;
- attendance can be recorded late without ambiguity;
- package entitlement is configurable and traceable;
- makeup/reschedule never double-consumes service rights;
- Montessori presentation and observation are separate evidence concepts;
- development progress is professional judgement rather than a single observation score;
- a new child waits for sufficient observation maturity before first report;
- published reports are immutable/versioned;
- authorization depends on intentional responsibility/context rather than accidental mutable session links;
- legacy tables can be retired without unexplained data loss.
