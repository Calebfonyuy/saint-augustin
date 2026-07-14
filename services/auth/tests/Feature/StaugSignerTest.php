<?php

use App\Services\Staug\StaugSigner;

/*
 * StaugSigner — HMAC-SHA256 over a domain-separation prefix + raw manifest
 * bytes (FR-DX-3). The test signing key is set in phpunit.xml.dist.
 */

test('sign then verify round-trips', function () {
    $signer = new StaugSigner();
    $bytes = '{"format":"STAUG","version":"1"}';

    $sig = $signer->sign($bytes);

    expect($sig)->toBeString()->toMatch('/^[0-9a-f]{64}$/');
    expect($signer->verify($bytes, $sig))->toBeTrue();
});

test('verify fails when a single manifest byte is flipped', function () {
    $signer = new StaugSigner();
    $bytes = '{"format":"STAUG","version":"1"}';
    $sig = $signer->sign($bytes);

    expect($signer->verify($bytes.' ', $sig))->toBeFalse();
});

test('verify fails when the signature is altered', function () {
    $signer = new StaugSigner();
    $bytes = 'payload';
    $sig = $signer->sign($bytes);

    // Flip the last hex nibble.
    $tampered = substr($sig, 0, -1).($sig[-1] === '0' ? '1' : '0');

    expect($signer->verify($bytes, $tampered))->toBeFalse();
});

test('a different signing key produces a different, non-verifying signature', function () {
    $signer = new StaugSigner();
    $bytes = 'payload';
    $sig = $signer->sign($bytes);

    config(['staug.signing_key' => 'a-completely-different-key-also-32-characters']);

    expect($signer->sign($bytes))->not->toBe($sig);
    expect($signer->verify($bytes, $sig))->toBeFalse();
});

test('signing throws when the key is not configured', function () {
    config(['staug.signing_key' => '']);

    (new StaugSigner())->sign('payload');
})->throws(RuntimeException::class);
