# M17.3B — Manual Validation Gate

This document is the temporary release gate for **M17.3B — Target Session Occurrence + Booking Write Cutover** while GitHub Actions is intentionally unavailable because of the account usage limit.

GitHub Actions being unavailable is **not** equivalent to CI green. M17.3B must not be merged until every required local gate below is executed against the exact PR head and the results are recorded.

## 1. Exact-head verification

Run from the local repository before any validation:

```powershell
git fetch origin
git status --short
git rev-parse HEAD
git rev-parse origin/main
git rev-parse origin/refactor/m17-target-session-write-cutover
git log -1 --oneline
```

Requirements:

- working tree is clean;
- local `HEAD` equals `origin/refactor/m17-target-session-write-cutover`;
- exact tested SHA is recorded;
- current `origin/main` SHA is recorded;
- do not test an older local branch and report it as the PR head.

## 2. Environment preparation

Mirror the disabled GitHub Actions environment as closely as practical:

```powershell
Copy-Item .env.example .env -Force
composer install --no-interaction --prefer-dist --optimize-autoloader
php artisan key:generate
npm ci
```

The canonical M17 cutover defaults must remain:

```env
MONTESSORI_SCHEDULING_READ_SOURCE=target
MONTESSORI_SCHEDULING_WRITE_SOURCE=target
MONTESSORI_SESSION_WRITE_SOURCE=target
```

Do not switch production defaults to `legacy` just to make a test pass.

## 3. Targeted M17.3B regression gate

Run the tranche-specific tests first so failures are easier to diagnose:

```powershell
php artisan test tests/Feature/TargetSessionWriteCutoverTest.php
php artisan test tests/Feature/SessionCompatibilitySelfHealingTest.php
php artisan test tests/Feature/SessionAuthorizationFailClosedTest.php
php artisan test tests/Feature/SessionDeleteEvidenceGuardTest.php
php artisan test tests/Feature/SessionTerminalBookingGuardTest.php
php artisan test tests/Feature/BookingPresentationEvidenceGuardTest.php
php artisan test tests/Feature/MakeupPresentationEvidenceGuardTest.php
php artisan test tests/Feature/LegacySessionRollbackDeleteGuardTest.php
php artisan test tests/Feature/DatabaseSeederTargetReconciliationTest.php
php artisan test tests/Feature/SessionTemplateOccurrenceTest.php
php artisan test tests/Feature/TargetScheduleWriteCutoverTest.php
php artisan test tests/Feature/TargetSchedulingReadCutoverTest.php
php artisan test tests/Feature/BookingMovementRescheduleTest.php
php artisan test tests/Feature/AttendanceSemanticsTest.php
php artisan test tests/Feature/AttendanceCreditMakeupTest.php
php artisan test tests/Feature/LegacyContractionReadinessTest.php
php artisan test tests/Feature/AuthorizationTest.php
php artisan test tests/Feature/SecurityHardeningTest.php
```

Required semantics covered by this gate include:

- target-first occurrence creation;
- canonical `ChildSessionBooking` roster authority;
- stale legacy session/pivot state cannot override target state;
- same-day / capacity / time conflicts are validated from target state;
- explicit `unmarked` attendance remains `unmarked` when a session closes;
- school cancellation preserves historical membership and existing makeup/credit policy;
- reschedule preserves source history and booking movement lineage;
- terminal bookings cannot be resurrected through generic roster updates or compatibility sync;
- missing compatibility mirrors can self-heal from canonical target state;
- orphan teacher accounts fail closed rather than gaining session mutation access;
- marked attendance, observation, presentation, credit, and movement history block destructive session/booking mutations where applicable;
- presentation evidence is historical pedagogical evidence, not attendance fulfillment or automatic progress/mastery;
- makeup refuses a contradictory source booking that has presentation evidence;
- explicit legacy rollback mode still bridges changes to the target domain without weakening lifecycle/history safety;
- the normal test harness uses the target-authoritative default rather than globally masking seed execution as legacy;
- `DatabaseSeeder` produces canonical session/booking lineage and reconciliation-ready marked attendance while restoring the configured write source after its scoped historical fixture bridge.

## 4. Full PHP regression gate

After the targeted tests pass:

```powershell
php artisan test
```

Do not merge based only on the targeted tests.

## 5. Fresh-seed + legacy reconciliation gate

The historical demo fixture block intentionally seeds legacy-shaped `ClassSession` rows inside a **scoped** `MONTESSORI_SESSION_WRITE_SOURCE=legacy` bridge context. It then restores the previous configuration and links seeded marked attendance to canonical `ChildSessionBooking` rows. Runtime/default authority remains `target`.

Verify this from a disposable local database. Do not run destructive migration commands against production data.

For the normal SQLite/dev validation environment:

```powershell
php artisan migrate:fresh --seed
php artisan legacy:reconcile
```

Requirements:

- seed completes without manual database repair;
- runtime config returns to target-authoritative defaults after seeding;
- seeded marked attendance has canonical booking lineage;
- `legacy:reconcile` exits successfully and reports the baseline as ready/reconciled.

The automated regression `DatabaseSeederTargetReconciliationTest.php` must also pass in the targeted and full PHP gates.

If reconciliation fails:

1. identify the exact failed check;
2. inspect missing / duplicate / mismatch identities;
3. fix source or compatibility lineage;
4. do not manually massage the database and call the gate green;
5. only change the reconciliation definition if the canonical domain definition has genuinely changed.

## 6. Pint gate

The disabled GitHub workflow checks only changed PHP files. On Windows PowerShell, a stricter full-project check is acceptable and preferred:

```powershell
./vendor/bin/pint --test
```

If desired, the exact CI-equivalent changed-file scope can also be reproduced from the PR base, but a full Pint check must not be weakened to hide formatting failures.

## 7. Frontend dependency + production build gate

GitHub Actions previously used Node.js 22 and `npm ci`.

Run:

```powershell
node --version
npm --version
npm ci
npm run build
```

Record the Node version used. `npm install` is not a replacement for the release gate when `package-lock.json` exists; the canonical CI-equivalent command is `npm ci`.

## 8. Rollback switch smoke gate

The dedicated operational session rollback switch must remain real:

```env
MONTESSORI_SESSION_WRITE_SOURCE=legacy
```

Regression coverage includes rollback create → update → close plus lifecycle/history delete guards. When manually smoke-testing the UI, verify rollback mode is temporary and restore:

```env
MONTESSORI_SESSION_WRITE_SOURCE=target
```

Clear cached configuration if the local environment uses config cache:

```powershell
php artisan optimize:clear
```

Do not leave the local or deployed environment in legacy mode unintentionally.

## 9. Minimal browser/UAT smoke gate

With target mode active, verify at least:

1. create a session from a weekly schedule;
2. update room/time/participant roster;
3. mark one child present and leave another explicitly unmarked;
4. close the session and confirm unmarked is not converted to absent;
5. reschedule one eligible booking and verify source history remains visible;
6. cancel an eligible child booking;
7. exercise school/session cancellation on a disposable fixture and verify history is retained;
8. verify a terminal booking cannot be silently re-added through a generic roster update;
9. verify destructive mutation is refused when attendance/pedagogical evidence exists;
10. verify a teacher cannot mutate another teacher's session;
11. verify an orphan teacher user without a Teacher profile receives 403;
12. re-run `php artisan legacy:reconcile` after the target-authoritative mutations.

Do not perform destructive smoke tests against production data.

## 10. Merge evidence to record

Before requesting merge, record:

- exact tested PR SHA;
- exact base/main SHA;
- targeted regression result;
- full `php artisan test` result;
- fresh `migrate:fresh --seed` result on a disposable local database;
- `legacy:reconcile` result;
- Pint result;
- `npm ci` result;
- production build result;
- relevant browser/UAT result;
- any known limitations that remain compatibility-only.

A later commit to the PR invalidates prior exact-head evidence. If the PR head changes after testing, rerun the required gates against the new exact head.

## M17.4 boundary

Passing this M17.3B gate does **not** authorize dropping legacy tables.

Before M17.4 contraction, separately prove zero active production dependencies and resolve remaining legacy-held evidence such as compatibility-only class notes/follow-up recommendation fields and observation relationships that still reference `class_sessions`.
