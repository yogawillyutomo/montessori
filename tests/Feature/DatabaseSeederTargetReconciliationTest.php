<?php

namespace Tests\Feature;

use App\Services\Migration\LegacyContractionReadinessService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSeederTargetReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_is_reconciled_when_runtime_default_is_target(): void
    {
        config()->set('montessori.session.write_source', 'target');

        app(DatabaseSeeder::class)->run();

        $this->assertSame('target', config('montessori.session.write_source'));

        $markedTotal = DB::table('attendances')
            ->whereNotNull('marked_at')
            ->count();
        $linkedMarkedTotal = DB::table('attendances')
            ->whereNotNull('marked_at')
            ->whereNotNull('child_session_booking_id')
            ->count();

        $this->assertGreaterThan(0, $markedTotal);
        $this->assertSame($markedTotal, $linkedMarkedTotal);

        $readiness = app(LegacyContractionReadinessService::class);
        $this->assertTrue(
            $readiness->dataReady(),
            json_encode($readiness->report(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        $this->artisan('legacy:reconcile')->assertExitCode(0);
    }
}
