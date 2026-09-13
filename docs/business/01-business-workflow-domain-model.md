# Montessori Bloom — Business Workflow & Domain Model

Version: **v0.2 baseline**  
Status: **Business baseline / pre-implementation**

## 1. Product positioning

Montessori Bloom is primarily a **Montessori Child Development & Learning Documentation Platform**.

Its core value is:

> Observe -> Understand -> Guide -> Document -> Communicate

School administration, scheduling, attendance, packages and reporting support this flow. They are not the pedagogical core.

## 2. Domain philosophy

The system must not model Montessori as a conventional timetable where every child receives the same lesson at the same time.

The target mental model is:

```text
CHILD
  -> PREPARED ENVIRONMENT
  -> GUIDE OBSERVES
  -> PRESENTATION / CHILD WORK
  -> PRACTICE & REPETITION
  -> OBSERVATION / EVIDENCE
  -> GUIDE PROFESSIONAL DECISION
  -> DEVELOPMENT RECORD
  -> PROGRESS REPORT / FAMILY COMMUNICATION
```

The operational and pedagogical layers must remain separate:

```text
Operational context
Schedule / Session / Attendance

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

## 3. Current operating facts

The current school implementation has these important characteristics:

- a child commonly attends 1–2 times per week;
- a child participates in at most one session per calendar day;
- one school can run up to around five sessions in a day;
- session count, times and capacities must remain configurable;
- slot movements occur frequently;
- attendance is not required in real time;
- attendance can be entered during the session, after the session, at the end of the day, or later;
- there is no automatic attendance deadline.

## 4. Session

A **Session** is the operational container for a child's attendance and Montessori activity during a defined period on a date.

A session is not:

- a subject;
- one activity;
- one material;
- one presentation;
- one observation.

A session may contain many independent child activities and many guide interactions.

Example:

```text
SESSION 1

Alya
  - Practical Life
  - Sensorial

Raka
  - Mathematics
  - Practical Life

Guide
  - presents one material to Alya
  - observes Raka
  - writes one meaningful observation
```

Session is therefore an **operational backbone**, not the pedagogical source of truth.

## 5. Session lifecycle

Recommended conceptual states:

```text
PLANNED -> OPEN -> COMPLETED
             \
              -> CANCELLED
```

`IN_PROGRESS` may be added if it has real operational value, but it is not required merely for technical completeness.

A session may be completed while one or more attendance records are still `UNMARKED`.

Completion of a session must not require attendance completion.

## 6. Administrative class vs Montessori environment

Do not force administrative grouping and Montessori pedagogical grouping to be the same concept.

### Administrative Class

Used for:

- formal school administration;
- external reporting;
- billing/grouping if required by the institution.

### Montessori Environment

Used for:

- prepared environment;
- mixed-age community;
- guide assignment;
- child membership;
- pedagogical context.

Conceptually:

```text
CHILD
  + Administrative Class
  + Environment Membership
```

## 7. Guide assignment

Authorization must not rely only on a single global `teacher_id`.

The system should be able to express context such as:

- Lead Guide;
- Guide;
- Assistant;
- Specialist;
- Primary/Responsible Guide.

Access should eventually be derived from a combination of:

```text
System Role
+ Environment Assignment
+ Child Responsibility
+ Session Context
```

Participating in one session with a child must not automatically grant permanent access to all of that child's sensitive historical information.

## 8. Daily child journey

Typical operational/pedagogical flow:

```text
ARRIVAL
  -> SESSION / ENVIRONMENT
  -> CHILD WORK
  -> GUIDE OBSERVATION
  -> PRESENTATION when appropriate
  -> INDEPENDENT PRACTICE / REPETITION
  -> MEANINGFUL OBSERVATION
  -> SESSION ENDS
```

Attendance may be recorded at any point, including after the session has ended.

Attendance must not act as a gating step for pedagogical recording.

## 9. Presentation

`Presentation` should be a first-class domain entity.

It records that a material/activity was intentionally introduced to a child by a guide.

Minimum conceptual data:

- child;
- material/activity;
- guide;
- date;
- session/context;
- presentation type;
- optional note.

A presentation is different from an observation.

## 10. Practice

Children may repeat or revisit work many times. Montessori Bloom must not require every practice event to be recorded.

Practice records may be optional.

Principle:

> Do not turn the guide into a data-entry operator.

Meaningful events should be easy to capture quickly.

## 11. Observation

Observation is core pedagogical evidence.

An observation may contain:

- objective note;
- context;
- activity/material;
- development area;
- guide interpretation;
- follow-up flag;
- optional evidence/attachment.

Observation must not be equivalent to a numeric score.

## 12. Observation vs development progress

One observation is not a development conclusion.

Example longitudinal history:

```text
Pouring Water
01 Sep -> Presented
04 Sep -> Practicing with assistance
08 Sep -> Practicing
14 Sep -> Independent
```

Development Progress is derived from a body of evidence plus professional judgement:

```text
Presentations
+ Observations
+ Optional Practice Evidence
+ Guide Professional Judgement
-> Development Progress
```

A single observation should not automatically finalize development status unless a specific business rule is deliberately introduced.

## 13. Development progress

Possible internal terminology, subject to school validation:

```text
NOT_INTRODUCED
PRESENTED
PRACTICING
DEVELOPING
INDEPENDENT
MASTERED
```

These labels are not child grades. They represent pedagogical progression.

## 14. Next-step recommendations

The system may help guides identify possible next actions, for example:

- possible next presentation;
- consider re-presentation;
- observe one development area more closely;
- evidence coverage is low across recent attended sessions.

But:

```text
SYSTEM SUGGESTS
-> GUIDE REVIEWS
-> GUIDE DECIDES
```

The application must not autonomously make pedagogical decisions or diagnoses.

## 15. Attendance

Attendance is an operational record linked to a child and a concrete session occurrence.

Recommended statuses:

```text
UNMARKED
PRESENT
ABSENT
SICK
EXCUSED
LATE
```

### Important rules

- Attendance does not need to be realtime.
- It may be entered after the session or after the day has ended.
- There is no automatic deadline.
- `UNMARKED != ABSENT`.
- Never auto-convert `UNMARKED` to `ABSENT` merely because time passed.
- Session completion must not lock attendance.
- Authorized users may correct historical attendance.
- Corrections must be auditable.

The system must distinguish:

```text
session_date  = when attendance happened
recorded_at   = when it was entered into the system
```

A late entry changes `recorded_at`, not the historical date of attendance.

## 16. Attendance audit principle

Because attendance is flexible in time, the system should prefer:

> flexible recording + strict audit history

rather than:

> hard time locks

Minimum audit information for changes:

- student;
- session;
- previous status;
- new status;
- changed by;
- changed at.

## 17. Attendance and development context

Because children may attend only 1–2 times per week, developmental reminders should not rely only on calendar days.

Prefer contextual metrics such as:

> No Sensorial observation in the last 4 attended sessions.

rather than only:

> No Sensorial observation in the last 10 days.

An `UNMARKED` session must not be assumed present or absent when calculating attended-session context.

## 18. Follow-up / support

One weak or concerning observation must not automatically create a formal intervention plan.

Recommended flow:

```text
Observation
-> More Observation
-> Repeated Pattern?
   -> No: Continue Observation
   -> Yes: Follow-Up Candidate
           -> Guide Review
           -> Academic Review if needed
           -> Parent Discussion if appropriate
           -> Support Plan
           -> Periodic Review
```

The current term `ILP` should remain provisional until school terminology is validated.

## 19. Parent visibility

Raw professional working records should not automatically be parent-facing.

### Internal by default

- raw observations;
- private guide notes;
- draft concern/follow-up;
- internal development discussions;
- draft reports.

### Potentially parent-shareable

- attendance;
- curated progress highlights;
- approved support/follow-up summary;
- family conference summary;
- published progress reports.

Visibility must be explicit, not accidental.

## 20. Family conference

Family conference should be treated as a possible domain record, not merely chat history.

Potential data:

- child;
- date;
- guide;
- guardian participants;
- progress summary;
- strengths;
- areas to support;
- parent observations from home;
- agreed actions;
- next review.

Parent observations are contextual evidence, not automatically equivalent to a school observation.

## 21. Progress report

A report is a communication artifact built from accumulated evidence.

Potential sources:

```text
Presentation History
+ Observation History
+ Development Progress
+ Follow-Up Records
+ Guide Narrative
+ Attendance Context
```

Recommended lifecycle:

```text
DRAFT
-> SUBMITTED_FOR_REVIEW
-> UNDER_REVIEW
   -> REVISION_REQUESTED -> DRAFT
   -> APPROVED
-> PUBLISHED
-> ARCHIVED
```

Published reports should be historical snapshots. Corrections after publication should use a formal revision/reopen workflow rather than silent edits.

## 22. Business domains

### Organization & Academic Structure

- School
- Academic Year
- Term
- Administrative Class

### Montessori Environment

- Environment
- Environment Membership
- Guide Assignment
- Primary/Responsible Guide

### Scheduling & Session

- Session Template
- Session Occurrence
- Recurring Schedule
- Child Session Booking
- Attendance

### Montessori Curriculum

- Development Area
- Material / Activity
- Learning Goal
- Presentation Sequence / Related Activity

### Child Development — Core

- Presentation
- Observation
- Optional Practice Record
- Development Progress
- Next-Step Recommendation

### Support & Collaboration

- Follow-Up Candidate
- Support Plan
- Support Review
- Family Conference
- Parent Contribution

### Reporting

- Report Policy
- Report Cycle
- Report Eligibility
- Progress Report
- Review
- Approval
- Publication
- Revision

## 23. Key invariants

1. One child has at most one active session booking per calendar date.
2. Attendance is attached to the actual session occurrence.
3. `UNMARKED` is not absence.
4. Attendance may be recorded or corrected later.
5. Observation has an attributable observer.
6. Presentation has an attributable guide, child and activity/material.
7. One observation must not automatically equal a final development conclusion.
8. Session participation must not grant permanent global child access.
9. Parents only access children with an authorized relationship.
10. Parents only receive report content that is intended/published for them.
11. Published reports preserve history and must not be silently mutated.

## 24. MVP direction

### Must have

- school / academic period;
- users, children, guardians, guides;
- environments and memberships;
- recurring schedule and session occurrences;
- booking and capacity;
- delayed/retroactive attendance;
- attendance audit;
- development area and material/activity;
- presentation;
- observation;
- development progress;
- report eligibility;
- report draft/review/publish;
- basic parent visibility;
- authorization and audit.

### Should have

- follow-up candidate;
- family conference;
- next-step recommendation;
- attention dashboard;
- optional evidence attachment.

### Later

- AI recommendation;
- automatic narrative drafting;
- advanced analytics;
- sophisticated material inventory;
- predictive analysis.

## 25. Product north star

> Does the system help the guide understand each child and decide the next appropriate action without disrupting real interaction with the child?

If a feature mainly creates administrative work and does not improve school operations or child-development understanding, it should be challenged before implementation.