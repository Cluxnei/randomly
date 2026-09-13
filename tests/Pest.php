<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Unit tests bind to plain PHPUnit — nothing under tests/Unit boots the
| framework, which is what keeps the suite instant. Feature tests, when they
| arrive, get the Laravel TestCase.
|
*/

pest()->extend(TestCase::class)->in('Feature');
