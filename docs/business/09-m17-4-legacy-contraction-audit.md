# M17.4 — Legacy Contraction Readiness Audit

Status: **Implementation tranche / destructive deletion intentionally blocked**

## 1. Objective

M17.4 verifies whether the legacy scheduling structures introduced by the alpha can actually be contracted after the M17.2 / M17.3 read and write cutovers.

The key distinction is:

```text
DATA RECONCILIATION READY
!=
LEGACY DELETION READY
```

A clean mapping report only proves that known legacy identities have canonical target-domain counterparts. It does not prove that no runtime code, rollback path, foreign key, or compatibility-only field still depends on the legacy structures.

This tranche therefore follows the roadmap sequence:

```text
VERIFY
  -> CONTRACT SAFE DEPENDENCIES
  -> AUDIT REMAINING BLOCKERS
  -> DO NOT DROP UNTIL ZERO BLOCKERS
```

## 2. Source-of-truth baseline

M17.4 starts after merged M17.3B:

- base `main`: `972ade50f931192d08b54637e0677434d362c70e`;
- session occurrence + booking writes are target-authoritative by default;
- scheduling reads are target-authoritative by default;
- compatibility mirrors and the explicit legacy rollback switch still exist intentionally;
- `legacy:reconcile` may report data as ready, but that is not deletion authorization.

## 3. Safe contraction performed in M17.4

### Observation session context

The target ERD already defines:

```text
observations.session_occurrence_id nullable
```

M17.4 therefore safely adds that approved target relationship.

The migration:

- adds nullable `observations.session_occurrence_id`;
- backfills it from `class_session_id -> session_occurrences.legacy_class_session_id`;
- retains `class_session_id` as a compatibility foreign key for now;
- does not infer a session occurrence for spontaneous observations.

Scheduled observation writes still accept the legacy `class_session_id` UI handle, but canonical behavior now resolves:

```text
ClassSession compatibility handle
 -> SessionOccurrence
 -> ChildSessionBooking
```

Canonical occurrence/booking state is authoritative for:

- teacher session ownership;
- participant membership;
- stored observation occurrence lineage;
- booking evidence checks;
- target session deletion evidence guards;
- observation counts in target scheduling reads.

The legacy observation link remains only as transition compatibility.

## 4. Active legacy dependency inventory

### `weekly_schedules`

**Deletion status: BLOCKED**

Active uses include:

- compatibility handles returned to current scheduling UI;
- current route/controller model binding;
- explicit legacy rollback writer;
- compatibility synchronization and self-healing;
- historical/demo seeding.

Canonical authority exists in `session_templates`, but the legacy handle is still part of the live adapter contract.

### `student_weekly_schedule`

**Deletion status: BLOCKED**

Active uses include:

- compatibility many-to-many relationships;
- explicit mirror/self-healing paths;
- legacy rollback behavior;
- transition tests and reconciliation.

Canonical authority exists in `recurring_schedules`.

### `class_sessions`

**Deletion status: BLOCKED**

Active uses include:

- route model binding and compatibility UI handles;
- target read adapter materializing a `ClassSession` view object;
- attendance compatibility linkage;
- observation compatibility input/link;
- explicit legacy rollback writer;
- compatibility self-healing;
- two fields with no approved target home:
  - `class_note`;
  - `follow_up_recommendation`.

Canonical operational authority exists in `session_occurrences`, but the table cannot be dropped while those dependencies remain.

### `class_session_student`

**Deletion status: BLOCKED**

Active uses include:

- compatibility participant mirror;
- rollback writer;
- self-healing and migration lineage;
- current legacy many-to-many model relationships.

Canonical authority exists in `child_session_bookings`.

### `attendances.class_session_id`

**Deletion status: BLOCKED**

Attendance outcome authority is booking-linked through `child_session_booking_id`, but the legacy foreign key remains present and is still maintained by compatibility writers.

Dropping `class_sessions` is impossible while this constrained column remains.

### `observations.class_session_id`

**Deletion status: BLOCKED, but contraction has started**

M17.4 adds and backfills `session_occurrence_id`, and new scheduled observation writes record canonical occurrence lineage.

The legacy column remains because current UI/session compatibility handles still submit `class_session_id`.

A later tranche may retire it only after the UI/input contract has moved to canonical occurrence IDs and reconciliation remains clean.

### Session note fields

```text
class_sessions.class_note
class_sessions.follow_up_recommendation
```

**Deletion status: BLOCKED**

There is currently no approved target-domain home for these fields.

M17.4 does not invent one.

A future architecture decision must explicitly define whether they become:

- occurrence operational notes;
- a separate immutable note/event entity;
- another approved domain concept.

Until then, legacy `class_sessions` must remain.

### Legacy rollback

**Deletion status: BLOCKED**

```env
MONTESSORI_SESSION_WRITE_SOURCE=legacy
```

remains an intentional operational rollback path.

Removing the underlying legacy structures while advertising that switch would create a false rollback guarantee.

## 5. Reconciliation gate

`legacy:reconcile` now additionally verifies:

```text
scheduled observations -> session_occurrences
```

For each observation with a legacy session context, it requires:

- a populated `session_occurrence_id`;
- an existing target occurrence;
- the target occurrence's `legacy_class_session_id` to match the observation's compatibility session ID.

Any missing or mismatched mapping blocks `dataReady()`.

## 6. Deletion-readiness audit

M17.4 adds:

```powershell
php artisan legacy:contraction-audit
```

The audit checks at least:

- data reconciliation;
- scheduled observation target linkage;
- all attendance rows linked to canonical bookings;
- retirement of `observations.class_session_id`;
- retirement of `attendances.class_session_id`;
- approved target home for session notes;
- retirement of the compatibility read model;
- retirement of the legacy rollback writer.

### Expected result for M17.4

The command is expected to report:

```text
DELETION READINESS: BLOCKED. Keep legacy structures in place.
```

and exit with code `1`.

That is the correct result for this tranche.

Do not change the command to exit green merely to satisfy a deployment checklist. It becomes green only after every deletion blocker is genuinely removed.

## 7. Required regression evidence

Run at minimum:

```powershell
php artisan test tests/Feature/ObservationSessionOccurrenceCutoverTest.php
php artisan test tests/Feature/LegacyContractionAuditTest.php
php artisan test tests/Feature/LegacyContractionReadinessTest.php
php artisan test tests/Feature/ScheduledFollowUpSignalTest.php
php artisan test tests/Feature/AuthorizationTest.php
php artisan test tests/Feature/SessionDeleteEvidenceGuardTest.php
php artisan test tests/Feature/TargetSchedulingReadCutoverTest.php
php artisan test tests/Feature/TargetSessionWriteCutoverTest.php
```

Then run the full regression suite:

```powershell
php artisan test
```

Fresh disposable database proof:

```powershell
php artisan migrate:fresh --seed
php artisan legacy:reconcile
php artisan legacy:contraction-audit
```

Expected:

- migration + seed: PASS;
- `legacy:reconcile`: exit `0`, data ready;
- `legacy:contraction-audit`: exit `1`, deletion blocked by explicit known dependencies.

Also run:

```powershell
./vendor/bin/pint --test
npm ci
npm run build
```

## 8. M17.4 exit criteria

This tranche is ready to merge when:

- observation target occurrence migration/backfill passes;
- new scheduled observations record `session_occurrence_id`;
- observation authority uses canonical occurrence/booking state;
- target read/evidence guards work without relying on the legacy observation FK;
- reconciliation detects missing/mismatched observation occurrence lineage;
- deletion audit reports every remaining blocker explicitly;
- no legacy table or FK is dropped;
- full tests, Pint, fresh seed/reconciliation, and frontend build pass;
- PR diff contains no M17.5/destructive-drop work.

## 9. What comes after M17.4

A later contraction tranche may proceed only after explicit decisions and implementation for:

1. canonical UI/model binding without legacy `WeeklySchedule` / `ClassSession` handles;
2. target home for session notes;
3. removal of attendance and observation legacy foreign keys;
4. retirement of compatibility mirrors;
5. retirement of the legacy rollback switch;
6. production backup/snapshot and restore rehearsal;
7. fresh zero-blocker `legacy:contraction-audit`.

Only then is destructive legacy-table removal eligible for its own migration and release gate.
