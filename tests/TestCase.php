<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed legacy-shaped demo fixtures through the explicit rollback bridge, then
     * restore the target-authoritative runtime default for the actual test.
     *
     * @param  list<string>|class-string<\Illuminate\Database\Seeder>|string  $class
     * @return $this
     */
    public function seed($class = 'Database\\Seeders\\DatabaseSeeder')
    {
        $previous = config('montessori.session.write_source', 'target');
        config()->set('montessori.session.write_source', 'legacy');

        try {
            parent::seed($class);
        } finally {
            config()->set('montessori.session.write_source', $previous);
        }

        return $this;
    }
}
