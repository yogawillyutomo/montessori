<?php

return [
    'scheduling' => [
        // M17 cutover switches for recurring/template scheduling.
        'read_source' => env('MONTESSORI_SCHEDULING_READ_SOURCE', 'target'),
        'write_source' => env('MONTESSORI_SCHEDULING_WRITE_SOURCE', 'target'),
    ],

    'session' => [
        // M17.3B uses a separate rollback boundary because operational occurrence /
        // booking writes have a different blast radius from recurring schedules.
        'write_source' => env('MONTESSORI_SESSION_WRITE_SOURCE', 'target'),
    ],
];
