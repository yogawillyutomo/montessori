# Montessori Bloom — Business Documentation

Status: **Business baseline / pre-implementation**  
Last reviewed: **2026-09-13**

This directory contains the current business and domain baseline for Montessori Bloom. The purpose of these documents is to make the software follow the real operating model of the school instead of forcing school operations into the current implementation.

## Product direction

Montessori Bloom is positioned primarily as a **Montessori Child Development & Learning Documentation Platform**.

The product north star is:

> Help guides understand each child's development and decide the next appropriate action without turning the guide into a data-entry operator.

Operational modules such as schedule, session, attendance, enrollment, package and billing support that goal. They are not the pedagogical source of truth.

## Documents

1. [Business Workflow & Domain Model](./01-business-workflow-domain-model.md)
   - product positioning
   - environment, session and child journey
   - presentation, observation and development progress
   - attendance behavior
   - reporting and parent visibility

2. [Scheduling, Reschedule, Cancellation & Makeup](./02-scheduling-reschedule-makeup.md)
   - recurring schedules and actual session occurrences
   - child bookings
   - one-time and permanent schedule changes
   - reschedule history
   - cancellation, makeup and capacity rules

3. [Enrollment, Session Entitlement & Package Rules](./03-enrollment-entitlement-package-rules.md)
   - flexible plans such as Infant 8x/month and Glow 4x/month
   - entitlement and session credits
   - package changes and adjustments
   - reschedule/makeup interaction with credits
   - billing boundary

4. [Report Eligibility & Observation Maturity](./04-report-eligibility-observation-maturity.md)
   - first-report eligibility
   - minimum observation duration
   - attended-session requirements
   - guide confirmation
   - children joining mid-period
   - insufficient evidence handling

## Core separations

The architecture must preserve these distinctions:

```text
PROGRAM / LEVEL
    != SESSION PLAN
    != RECURRING SCHEDULE
    != SESSION BOOKING
    != ATTENDANCE
    != PEDAGOGICAL EVIDENCE
    != DEVELOPMENT CONCLUSION
    != PROGRESS REPORT
    != BILLING
```

Another important separation is:

```text
Operational context
Session / Schedule / Attendance

        !=

Pedagogical evidence
Presentation / Observation

        !=

Pedagogical conclusion
Development Progress

        !=

Communication artifact
Progress Report
```

## Important current business facts

- A child commonly attends **1–2 times per week**.
- A child attends **at most one session per calendar day**.
- A school may run **up to around five sessions in a day**, but this must remain configurable.
- Slot movements/reschedules are expected to happen frequently.
- Attendance does **not** need to be recorded in real time.
- Attendance may be entered at the end of a session, at the end of the day, or later.
- There is **no automatic attendance deadline**.
- `UNMARKED` must never be treated as `ABSENT`.
- Program session entitlement is configurable; examples include **Infant 8 sessions/month** and **Glow 4 sessions/month**.
- A child joining mid-period may participate and accumulate observations immediately while still being **not yet eligible for a progress report**.
- Initial report eligibility may require **2–3 months or another configurable period**, combined with actual attended sessions and guide confirmation.

## Change policy

These documents are business baselines, not immutable specifications. When real school practice contradicts a rule here, update the business documentation first and then change the implementation deliberately.

Avoid silently changing historical meaning in database migrations or controller logic without updating the relevant business rule.