<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // Keep the framework's default seeding behavior. DatabaseSeeder itself owns
    // the narrow legacy-bridge context needed for historical compatibility fixtures.
}
