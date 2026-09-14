<?php

return [
    'scheduling' => [
        // M17 cutover switch. `target` makes session_templates/session_occurrences
        // authoritative for process read models while legacy rows remain as
        // compatibility handles for routes that have not been retired yet.
        // Set to `legacy` only as an explicit rollback path during cutover.
        'read_source' => env('MONTESSORI_SCHEDULING_READ_SOURCE', 'target'),
    ],
];
