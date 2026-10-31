<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Pest-style feature tests (in tests/ and in each module) run on the Laravel
| TestCase, which refuses non-*_test databases (tests/TestCase.php).
|
*/

pest()->extend(TestCase::class)->in('Feature', '../modules/*/tests/Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Module names discovered from modules/* (ADR-0001). Used by the architecture tests.
 *
 * @return list<string>
 */
function modules(): array
{
    return array_values(array_map('basename', glob(dirname(__DIR__).'/modules/*', GLOB_ONLYDIR) ?: []));
}
