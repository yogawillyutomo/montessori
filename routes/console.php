<?php

use App\Services\Migration\LegacyContractionAuditService;
use App\Services\Migration\LegacyContractionReadinessService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('legacy:reconcile', function (LegacyContractionReadinessService $readiness): int {
    $report = $readiness->report();

    $labels = [
        'weekly_schedule_templates' => 'weekly_schedules -> session_templates',
        'student_recurring_schedules' => 'student_weekly_schedule -> recurring_schedules',
        'session_occurrences' => 'class_sessions -> session_occurrences',
        'session_bookings' => 'class_session_student -> child_session_bookings',
        'scheduled_observation_occurrences' => 'scheduled observations -> session_occurrences',
        'marked_attendance_bookings' => 'marked attendance -> child_session_booking',
    ];

    $rows = [];
    foreach ($report as $key => $check) {
        $rows[] = [
            $labels[$key] ?? $key,
            $check['table_ready'] ? 'yes' : 'NO',
            $check['legacy_total'],
            $check['mapped_total'],
            $check['missing_total'],
            $check['duplicate_total'],
            $check['mismatch_total'],
        ];
    }

    $this->table(
        ['Check', 'Tables', 'Legacy', 'Mapped', 'Missing', 'Duplicate', 'Mismatch'],
        $rows,
    );

    if (! $readiness->dataReady()) {
        $this->error('DATA RECONCILIATION: NOT READY. Legacy contraction must remain blocked.');

        return 1;
    }

    $this->info('DATA RECONCILIATION: READY.');
    $this->warn('This does NOT authorize dropping legacy structures. Source-of-truth cutover, zero active legacy code paths, production backup, and rollback readiness are separate mandatory gates.');

    return 0;
})->purpose('Reconcile legacy scheduling data against target-domain mappings before contraction');


Artisan::command('legacy:contraction-audit', function (LegacyContractionAuditService $audit): int {
    $report = $audit->report();

    $rows = [];
    foreach ($report as $key => $check) {
        $rows[] = [
            $key,
            $check['ready'] ? 'READY' : 'BLOCKED',
            $check['detail'],
        ];
    }

    $this->table(['Gate', 'Status', 'Detail'], $rows);

    if (! $audit->deletionReady()) {
        $this->error('DELETION READINESS: BLOCKED. Keep legacy structures in place.');

        return 1;
    }

    $this->info('DELETION READINESS: READY.');

    return 0;
})->purpose('Audit whether legacy scheduling structures are actually safe to delete');
