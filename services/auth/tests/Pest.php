<?php

/*
 * Pest test runner configuration.
 * Ref: https://pestphp.com/docs/configuring-pest
 */

uses(
    Tests\TestCase::class,
    Illuminate\Foundation\Testing\RefreshDatabase::class,
)->in('Feature');
