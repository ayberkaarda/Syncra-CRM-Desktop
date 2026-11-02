<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakesRedisWhenUnavailable;

abstract class TestCase extends BaseTestCase
{
    use FakesRedisWhenUnavailable;
}
