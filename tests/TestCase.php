<?php

namespace Tests;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests post to web routes without CSRF tokens.
        $this->withoutMiddleware([
            ValidateCsrfToken::class,
            VerifyCsrfToken::class,
        ]);
    }
}
