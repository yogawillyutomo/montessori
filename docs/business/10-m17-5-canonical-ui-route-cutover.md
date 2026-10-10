# M17.5 — Canonical Scheduling UI & Route Cutover

Status: **Implementation tranche / target UI identity cutover**

## 1. Objective

M17.5 removes legacy scheduling rows as the identity used by the live process UI.

The current browser and mutation contract moves from:

```text
WeeklySchedule
ClassSession
```

to:

```text
SessionTemplate
SessionOccurrence
```

This is an identity and route cutover, not yet a destructive schema contraction.

The legacy tables remain compatibility mirrors until later M17 tranches.

## 2. Baseline

M17.5 starts from merged M17.4:

```text
main = 5e4e3b5955fd55b442c77bbda25757127e1bb8fd
```

M17.4 already established:

- target-authoritative scheduling/session data;
- observation -> SessionOccurrence lineage;
- reconciliation proof;
- explicit deletion-readiness blockers;
- no authorization to drop legacy structures.

## 3. Canonical process UI contract

### Schedule UI

Current schedule screens read `SessionTemplate` directly.

Mutation routes bind:

```text
/process/schedules/{sessionTemplate}
```

for update, toggle and delete.

The create route writes a target `SessionTemplate` first.

The browser no longer sends `weekly_schedule_id` from the process UI.

### Session / attendance UI

Current session screens read `SessionOccurrence` directly.

Mutation routes bind:

```text
/process/sessions/{sessionOccurrence}
```

for:

- structural update;
- session notes;
- attendance;
- close;
- safe delete.

Session creation receives:

```text
session_template_id
session_date
```

The browser no longer sends a legacy schedule ID as its operational identity.

### Observation UI

Session-linked observation inputs use:

```text
session_occurrence_id
```

Monitoring snapshots are keyed by canonical occurrence ID.

Temporary compatibility acceptance of `class_session_id` remains server-side for older callers during M17.5, but the current UI no longer emits it.

## 4. Target model presentation relations

To preserve the existing UX without materializing legacy UI handles, target models expose presentation-oriented relations.

### SessionTemplate

- school class through `legacy_school_class_id`;
- teacher through `legacy_teacher_id`;
- active students through `recurring_schedules`.

### SessionOccurrence

- school class through `legacy_school_class_id`;
- teacher through `legacy_teacher_id`;
- active/current students through `child_session_bookings`;
- attendance through booking linkage;
- canonical observation count;
- attendance recap derived from booking-linked attendance.

The `legacy_*` names remain migration-lineage metadata. M17.5 does not reinterpret SchoolClass as Montessori Environment.

## 5. Authorization

Teacher schedule/session mutation authorization uses target rows:

```text
SessionTemplate.legacy_teacher_id
SessionOccurrence.legacy_teacher_id
```

and canonical booking membership.

A stale or corrupted `WeeklySchedule`, `ClassSession`, or legacy participant pivot must not expand authority.

The session mutation middleware resolves `sessionOccurrence` directly.

## 6. Compatibility mirrors

M17.5 intentionally keeps compatibility mirrors alive.

Target writes continue mirroring to:

```text
weekly_schedules
student_weekly_schedule
class_sessions
class_session_student
```

because later contraction work still needs:

- legacy FK compatibility;
- current session note fields;
- explicit operational rollback;
- migration/reconciliation proof.

The important change is that current UI identity no longer depends on those rows.

## 7. Rollback semantics

M17.5 must not silently invalidate the rollback flags.

When:

```env
MONTESSORI_SCHEDULING_WRITE_SOURCE=legacy
MONTESSORI_SESSION_WRITE_SOURCE=legacy
```

the browser still submits canonical template/occurrence IDs.

The write facade resolves the associated legacy mirror internally, executes the legacy rollback writer, and existing bridge logic synchronizes target state.

Therefore:

```text
canonical route identity
!=
rollback writer retirement
```

Rollback retirement belongs to a later M17 tranche.

## 8. Temporary compatibility inputs

For one transition tranche, server-side controllers may still accept:

```text
weekly_schedule_id
class_session_id
```

from older callers when the canonical ID is absent.

The live process UI must not emit those fields.

These input aliases are scheduled for removal with compatibility retirement, after exact-head regression evidence proves all supported callers are canonical.

## 9. Session notes remain a blocker

M17.5 deliberately does not invent a target home for:

```text
class_note
follow_up_recommendation
```

The session UI is occurrence-bound, but these two values are still read/written through the legacy ClassSession compatibility row.

M17.6 must make an explicit domain decision before retiring that dependency.

## 10. Contraction audit change

The `compatibility_read_model_retired` gate now verifies that all live schedule/session mutation routes bind canonical parameter names:

```text
sessionTemplate
sessionOccurrence
```

It is no longer a hardcoded READY claim.

The overall deletion audit remains BLOCKED after M17.5 because other blockers still exist.

## 11. Required regression evidence

Run at minimum:

```powershell
php artisan test tests/Feature/CanonicalSchedulingUiCutoverTest.php
php artisan test tests/Feature/TargetScheduleWriteCutoverTest.php
php artisan test tests/Feature/TargetSessionWriteCutoverTest.php
php artisan test tests/Feature/SessionAuthorizationFailClosedTest.php
php artisan test tests/Feature/SessionDeleteEvidenceGuardTest.php
php artisan test tests/Feature/AttendanceSemanticsTest.php
php artisan test tests/Feature/SecurityHardeningTest.php
php artisan test tests/Feature/ObservationSessionOccurrenceCutoverTest.php
php artisan test tests/Feature/LegacyContractionAuditTest.php
php artisan test tests/Feature/LegacyContractionReadinessTest.php
php artisan test tests/Feature/ControllerBoundaryTest.php
php artisan test tests/Feature/ExampleTest.php
```

Then:

```powershell
php artisan test
php artisan migrate:fresh --seed
php artisan legacy:reconcile
php artisan legacy:contraction-audit
./vendor/bin/pint --test
npm ci
npm run build
npm audit --omit=dev
```

Expected contraction result remains:

```text
DELETION READINESS: BLOCKED. Keep legacy structures in place.
```

with exit code `1`.

## 12. Browser UAT

At exact candidate HEAD verify at least:

1. schedule cards render from target templates;
2. create/edit/toggle schedule works;
3. session creation from a target template preserves the selected historical date;
4. attendance/session cards render from occurrences;
5. attendance mark/reset works;
6. close with UNMARKED still works;
7. teacher cannot operate another teacher's occurrence;
8. scheduled and quick observation forms submit canonical occurrence IDs;
9. existing session notes still round-trip through the temporary compatibility field.

## 13. Exit criteria

M17.5 is ready to merge only when:

- no process Blade form emits `weekly_schedule_id` or `class_session_id`;
- schedule mutation routes bind `SessionTemplate`;
- session mutation routes bind `SessionOccurrence`;
- canonical-vs-legacy ID divergence tests pass;
- target authorization remains fail closed;
- rollback still works behind canonical route identity;
- full regression suite passes;
- reconciliation remains READY;
- contraction audit remains explicitly BLOCKED for known later blockers;
- no destructive legacy drop is present.

## 14. Next tranche

M17.6 may begin only after M17.5 is merged.

Its scope is to decide and implement the canonical home for session-level notes, then retire the remaining attendance/observation legacy foreign-key dependencies where safe.

Do not remove the rollback mechanism or legacy tables in M17.6 unless their independent retirement gates are satisfied.
