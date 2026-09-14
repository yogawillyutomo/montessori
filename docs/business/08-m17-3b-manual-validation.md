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
- terminal bookings are not resurrected by compatibility sync;
- missing compatibility mirrors can self-heal from canonical target state;
- orphan teacher accounts fail closed rather than gaining session mutation access;
- explicit legacy rollback mode still bridges changes to the target domain.

## 4. Full PHP regression gate

After the targeted tests pass:

```powershell
php artisan test
```

Do not merge based only on the targeted tests.

## 5. Legacy reconciliation gate

Run:

```powershell
php artisan legacy:reconcile
```

The command must exit successfully and report the baseline as ready/reconciled. Do **not** change the readiness gate merely to ignore a mismatch.

If reconciliation fails:

1. identify the exact failed check;
2. inspect missing / duplicate / mismatch identities;
3. fix source or compatibility lineage;
4. only change the reconciliation definition if the canonical domain definition has genuinely changed.

### Fresh-seed caveat

The current M17.3B source audit identified a developer-fixture risk: `DatabaseSeeder` still creates historical `ClassSession` fixtures through legacy models, while legacy session model events are intentionally disabled when `MONTESSORI_SESSION_WRITE_SOURCE=target`.

Until that fixture path is explicitly corrected, **do not manually repair the database and call the gate green**. A fresh-seed reconciliation failure is evidence that the fixture path still needs a source fix.

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

The regression suite already contains create → update → close rollback coverage. When manually smoke-testing the UI, verify that rollback mode is temporary and restore:

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
8. verify a teacher cannot mutate another teacher's session;
9. verify an orphan teacher user without a Teacher profile receives 403;
10. re-run `php artisan legacy:reconcile` after the target-authoritative mutations.

Do not perform destructive smoke tests against production data.

## 10. Merge evidence to record

Before requesting merge, record:

- exact tested PR SHA;
- exact base/main SHA;
- targeted regression result;
- full `php artisan test` result;
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
