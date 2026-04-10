<?php

use App\Models\User;

test('user with admin role returns true for isAdmin', function () {
    $user = new User(['roles' => ['admin', 'musician']]);

    expect($user->isAdmin())->toBeTrue();
    expect($user->isMusician())->toBeTrue();
    expect($user->isProjectionist())->toBeFalse();
});

test('user with no roles defaults safely', function () {
    $user = new User(['roles' => []]);

    expect($user->isAdmin())->toBeFalse();
    expect($user->isMusician())->toBeFalse();
    expect($user->isProjectionist())->toBeFalse();
});

test('hasRole checks arbitrary role string', function () {
    $user = new User(['roles' => ['projectionist']]);

    expect($user->hasRole('projectionist'))->toBeTrue();
    expect($user->hasRole('admin'))->toBeFalse();
});
