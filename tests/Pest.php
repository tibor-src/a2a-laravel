<?php

declare(strict_types=1);

use Tests\Doubles\EchoAgent;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

afterEach(function (): void {
    EchoAgent::$onStream = null;
});
