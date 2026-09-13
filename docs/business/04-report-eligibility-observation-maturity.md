# Montessori Bloom — Report Eligibility & Observation Maturity

Version: **v0.1 baseline**  
Status: **Business baseline / pre-implementation**

## 1. Purpose

This document defines when a child:

- begins accumulating observations;
- has enough evidence to be considered for a progress report;
- is not yet eligible for a report;
- joins in the middle of a reporting period;
- has insufficient attendance/evidence;
- becomes eligible for a first report;
- moves into the regular reporting cycle afterward.

## 2. Core separation

The following concepts must remain separate:

```text
ENROLLMENT
  != SESSION ENTITLEMENT
  != ATTENDANCE
  != OBSERVATION
  != REPORT ELIGIBILITY
  != REPORT WORKFLOW
```

A child can be:

- actively enrolled;
- entitled to sessions;
- attending normally;
- accumulating observations;
- and still **not yet eligible for a progress report**.

## 3. Observation begins from the first session

A newly enrolled child should be observed from the first relevant session.

From day one the system may store:

- presentation history;
- observations;
- activity/material context;
- emerging development evidence.

But those records do not automatically produce a report.

## 4. Report eligibility policy

Each program/level may define a configurable **Report Eligibility Policy**.

Example:

```text
Program: Infant
Reporting Frequency: Monthly
Initial Observation Period: 2 months
Minimum Attended Sessions: 8
Guide Confirmation: Required
```

Another program may use:

```text
Program: Glow
Reporting Frequency: Monthly
Initial Observation Period: 3 months
Minimum Attended Sessions: 8
Guide Confirmation: Required
```

The values are business configuration, not code constants.

## 5. Minimum duration alone is not enough

Two children can both be enrolled for two months but have very different observation opportunities.

Example:

```text
Child A
Enrolled: 2 months
Attended: 14 sessions

Child B
Enrolled: 2 months
Attended: 3 sessions
```

Treating both as equally report-ready based only on time would be misleading.

Recommended initial eligibility considers a combination of:

```text
Minimum Observation Duration
+ Minimum Attended Sessions
+ Guide Professional Confirmation
```

Optional criteria may include evidence-area coverage.

## 6. Attended sessions are more meaningful than scheduled sessions

Eligibility should use **actual attended sessions**, not merely bookings or entitlements.

Count as attended when the attendance outcome is actually `PRESENT` (and optionally another state only if school policy explicitly defines it as an observation opportunity).

Do not count:

- `ABSENT`;
- `SICK`;
- `EXCUSED`;
- `RESCHEDULED_OUT`;
- `UNMARKED`.

A makeup or rescheduled destination session counts when the child actually attends it.

## 7. Unmarked attendance

`UNMARKED` must not be guessed.

An unmarked session does not yet count toward attended-session eligibility.

If it is later corrected to `PRESENT`, eligibility should be recalculated.

## 8. Children joining mid-period

A child who joins in the middle of a month may participate immediately and receive observations, but the child may be in an initial observation period.

Example:

```text
Enrollment: 20 Sep
September sessions: 3 attended
Report cycle: September
Result: NOT_ELIGIBLE
```

This is not a failed or missing report. It is an intentional business state.

## 9. Do not use score zero or blank report as a substitute

A child who is not yet report-eligible must not receive:

- score `0`;
- a misleading empty report;
- a status that suggests poor performance.

Use explicit states such as:

```text
NOT_ELIGIBLE
OBSERVATION_PERIOD_INCOMPLETE
INSUFFICIENT_EVIDENCE
```

Parent-facing wording should explain that the observation period is still in progress.

## 10. Observation duration calculation

Do not assume that entering during a calendar month means one full month of observation was completed.

Example:

```text
Joined: 20 Sep
Cutoff: 30 Sep
Observation duration: 10 days
```

The policy should be based on a clear duration model, for example:

- elapsed duration since observation/enrollment start;
- completed reporting periods;
- another explicit configured model.

Recommended MVP direction: **elapsed duration + actual attended sessions**.

## 11. First-report eligibility

Initial maturity requirements primarily apply to the **first report**.

Example:

```text
Initial policy: 2 months + 8 attended sessions

September -> NOT_ELIGIBLE
October   -> NOT_ELIGIBLE
November  -> FIRST REPORT ELIGIBLE
```

Once the first report is legitimately established, the child should not need to wait another 2–3 months before every subsequent report.

## 12. Subsequent report readiness

After the initial eligibility gate is passed, each later reporting cycle evaluates **current evidence readiness**.

These are separate concepts:

### Initial Report Eligibility

> Has this child been observed long enough to receive a first meaningful report?

### Current Report Readiness

> Is there enough relevant evidence in this reporting window to produce the current report responsibly?

A child may be historically eligible but temporarily have insufficient current evidence due to long absence or limited attendance.

## 13. Reporting frequency is configurable

Do not hardcode monthly reporting.

Support policy concepts such as:

```text
MONTHLY
BIMONTHLY
QUARTERLY
TERM
CUSTOM
```

A monthly reporting cycle does not mean every enrolled child automatically receives a report every month.

## 14. Report cycles

A report cycle has a cutoff date.

Eligibility must be evaluated against that cutoff.

Example:

```text
November cutoff: 30 Nov
Child meets 2-month requirement: 2 Dec
```

Result:

```text
November: NOT_ELIGIBLE
December: first eligible cycle
```

Do not automatically generate a retroactive November report just because eligibility was reached two days later.

## 15. First report evidence window

Recommended first report evidence window:

```text
Enrollment / Observation Start
-> First Report Cutoff
```

The first report may therefore summarize development across more than one calendar month.

## 16. Subsequent report evidence window

Recommended subsequent window:

```text
Previous Report Cutoff
-> Current Report Cutoff
```

The child's complete longitudinal record remains visible to authorized guides; the evidence window only defines the reporting snapshot.

## 17. Evidence coverage

The system may help identify whether development areas have enough recent evidence.

Example:

```text
Practical Life  -> evidence available
Sensorial       -> evidence available
Language        -> evidence available
Mathematics     -> low/no recent evidence
```

Coverage should usually be advisory, not an automatic failure gate, unless the school's Report Policy explicitly requires it.

## 18. Do not turn observation count into a teacher KPI

Avoid rules like:

```text
Teacher must create 20 observations per child
```

as a generic quality metric.

This may incentivize low-value data entry.

Observation count can help detect lack of coverage, but quality and longitudinal usefulness matter more than volume.

## 19. Guide confirmation

Even if objective criteria are met:

```text
Duration -> met
Attendance -> met
Coverage -> acceptable
```

the guide should remain the professional checkpoint for report readiness.

Recommended flow:

```text
SYSTEM CHECKS OBJECTIVE CRITERIA
    -> POTENTIALLY ELIGIBLE
    -> GUIDE REVIEWS EVIDENCE
       -> CONTINUE OBSERVATION
       or
       -> READY FOR REPORT
```

System assists; guide decides.

## 20. Authorized override

Exceptional cases may justify override, for example:

- returning child;
- transfer child with substantial reliable documentation;
- school-approved special case.

Override should require an authorized role and store:

- who approved;
- when;
- reason.

## 21. Program transfer

If a child moves from one program to another, do not delete or reset historical evidence.

Recommended default:

```text
Continuous enrollment within the same school
-> prior observation tenure remains visible
```

The new program may still require a new baseline or different report policy.

Whether initial maturity fully resets should be configurable if real school practice requires it.

## 22. Long absence

A child who was previously report-eligible can still be `INSUFFICIENT_CURRENT_EVIDENCE` in a later cycle after a long absence.

Historical eligibility does not force the school to produce a report with weak current evidence.

## 23. Report eligibility states

Keep initial eligibility simple:

```text
NOT_ELIGIBLE
ELIGIBLE
```

Attach reasons such as:

```text
MINIMUM_DURATION_NOT_MET
MINIMUM_ATTENDANCE_NOT_MET
GUIDE_CONFIRMATION_PENDING
```

Do not mix these states with the report drafting workflow.

## 24. Report workflow states

Current report workflow may use:

```text
NOT_STARTED
EVIDENCE_INCOMPLETE
READY_FOR_DRAFT
DRAFT
UNDER_REVIEW
REVISION_REQUESTED
APPROVED
PUBLISHED
ARCHIVED
```

Eligibility determines whether the child can enter the reporting process; it is not itself the report workflow.

## 25. Parent experience

For a child still in initial observation:

Prefer wording such as:

> The child is currently in the initial observation period. A progress report will be available after sufficient development evidence has been collected and reviewed by the guide.

Do not show a zero score or ambiguous dash that parents could interpret as failure.

## 26. Admin reporting dashboard

Possible operational overview:

```text
Eligible children                  42
Not yet eligible                    5
Insufficient current evidence       3
Drafted                            20
Under review                        8
Published                          14
```

## 27. Guide attention dashboard

Useful examples:

```text
Alya
First-report eligibility approaching; observation period still in progress

Bima
Duration met; attended-session threshold not yet met

Nala
Eligible; Language evidence coverage is low

Raka
Objective criteria met; guide confirmation pending
```

The goal is decision support, not pressure to generate reports prematurely.

## 28. Entitlement and report eligibility are independent

Valid example:

```text
September
Session entitlement: 8
Report eligibility: NOT_ELIGIBLE
```

The child still has normal service rights even if the report is not yet appropriate.

## 29. Billing and report eligibility are independent

A child may be paying/enrolled and legitimately not receive a progress report in the first month(s).

Do not make billing conditional on report creation, and do not make report creation automatic merely because billing is active.

## 30. Recommended policy model

Conceptual entities:

```text
ReportPolicy
ReportCycle
ReportEligibility
EvidenceWindow
ReportReadiness
```

Relationship:

```text
PROGRAM / LEVEL
    -> REPORT POLICY
    -> CHILD ENROLLMENT
    -> OBSERVATION MATURITY
    -> REPORT ELIGIBILITY
    -> REPORT CYCLE
    -> REPORT WORKFLOW
```

## 31. Example policy — Infant

```text
Policy: Infant Standard
Reporting Frequency: Monthly
Initial Observation Period: 2 months
Minimum Attended Sessions: 8
Guide Confirmation: Required
Area Coverage: Advisory
```

## 32. Example policy — Glow

```text
Policy: Glow Standard
Reporting Frequency: Monthly
Initial Observation Period: 3 months
Minimum Attended Sessions: 8
Guide Confirmation: Required
```

These examples demonstrate configurability; actual school policy remains editable.

## 33. Recommended MVP defaults

```text
Reporting Frequency: Configurable per program
Initial Report Eligibility: Configurable per program
Minimum Duration: Configurable
Minimum Attended Sessions: Configurable
Guide Confirmation: Required
Automatic Report Generation: Off
Insufficient Evidence: Do not force report/score
Child Joining Mid-Period: Observe immediately
First Report: Only after eligibility is met
Subsequent Reports: Follow normal cycles with current-readiness check
Attendance Deadline: None
Eligibility Override: Authorized role only
```

## 34. Key invariants

1. Observation may begin from the child's first session.
2. New enrollment does not automatically create a report.
3. `NOT_ELIGIBLE` is not a negative child assessment.
4. Entitlement and report eligibility are separate.
5. Billing and report eligibility are separate.
6. Actual attended sessions are a stronger input than scheduled sessions.
7. Rescheduled/makeup sessions count only if actually attended.
8. `UNMARKED` is not assumed attended.
9. Initial maturity requirements primarily gate the first report.
10. Subsequent report readiness evaluates current evidence.
11. Cutoff date determines eligibility for a reporting cycle.
12. Guide professional judgement remains the final pedagogical checkpoint.

## 35. Product principle

> **No evidence, no forced judgement.**

The software must not force a progress report simply because the calendar reached month-end. A responsible report should represent enough real observation to be meaningful.