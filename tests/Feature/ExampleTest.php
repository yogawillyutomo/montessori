<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_alpha_pages_return_successful_responses(): void
    {
        $this->seed();

        $this->get('/')->assertStatus(200)->assertSee('Dashboard Monitoring');
        $this->get('/master')->assertStatus(200)->assertSee('Master Data');
        $this->get('/process')->assertStatus(200)->assertSee('Proses Harian');
        $this->get('/reports')->assertStatus(200)->assertSee('Draft Rapor Otomatis');
    }
}
