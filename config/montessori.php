<?php

return [
    'scheduling' => [
        // M17 cutover switches. `target` makes the expanded scheduling domain
        // authoritative while legacy rows remain temporary compatibility mirrors.
        // Set either switch to `legacy` only as an explicit rollback path.
        'read_source' => env('MONTESSORI_SCHEDULING_READ_SOURCE', 'target'),
        'write_source' => env('MONTESSORI_SCHEDULING_WRITE_SOURCE', 'target'),
    ],
];
