<?php

use App\Services\VideoPsalm\Json5Decoder;

/*
 * Unit tests for the VideoPsalm `.vpagd` JSON-ish decoder.
 *
 * The decoder needs to accept three things stock json_decode rejects:
 *   1. unquoted identifier keys     → {Guid: "..."}
 *   2. raw newlines inside strings  → {Text: "line1\nline2"} (literal LF)
 *   3. trailing commas before } / ] (lenient mode for older exports)
 */

test('decodes unquoted identifier keys', function () {
    $out = Json5Decoder::decode('{Guid:"abc",Text:"hello"}');
    expect($out)->toBe(['Guid' => 'abc', 'Text' => 'hello']);
});

test('decodes quoted keys and standard JSON', function () {
    $out = Json5Decoder::decode('{"a":1,"b":[1,2,3],"c":true,"d":null,"e":false}');
    expect($out)->toBe([
        'a' => 1,
        'b' => [1, 2, 3],
        'c' => true,
        'd' => null,
        'e' => false,
    ]);
});

test('preserves raw newlines inside string literals', function () {
    // VideoPsalm verses embed real LF bytes between lines — not \n escapes.
    $out = Json5Decoder::decode("{Text:\"line1\nline2\nline3\"}");
    expect($out['Text'])->toBe("line1\nline2\nline3");
});

test('handles standard JSON escape sequences', function () {
    $out = Json5Decoder::decode('{"k":"a\\nb\\tc\\"d\\\\e"}');
    expect($out['k'])->toBe("a\nb\tc\"d\\e");
});

test('handles \u unicode escapes', function () {
    // Greek alpha = U+03B1
    $out = Json5Decoder::decode('{"k":"\\u03b1"}');
    expect($out['k'])->toBe('α');
});

test('parses integers and floats distinctly', function () {
    $out = Json5Decoder::decode('{a:1,b:1.5,c:-2,d:1e3}');
    expect($out['a'])->toBe(1)
        ->and($out['b'])->toBe(1.5)
        ->and($out['c'])->toBe(-2)
        ->and($out['d'])->toBe(1000.0);
});

test('tolerates trailing commas in objects and arrays', function () {
    $out = Json5Decoder::decode('{a:1,b:[1,2,3,],}');
    expect($out)->toBe(['a' => 1, 'b' => [1, 2, 3]]);
});

test('decodes nested objects and arrays', function () {
    $src = '{Verses:[{ID:0,Text:"a"},{Tag:1,ID:0,Text:"chorus"}]}';
    $out = Json5Decoder::decode($src);
    expect($out['Verses'])->toHaveCount(2)
        ->and($out['Verses'][0])->toBe(['ID' => 0, 'Text' => 'a'])
        ->and($out['Verses'][1])->toBe(['Tag' => 1, 'ID' => 0, 'Text' => 'chorus']);
});

test('decodes bare bool and null literals', function () {
    $out = Json5Decoder::decode('{a:true,b:false,c:null}');
    expect($out)->toBe(['a' => true, 'b' => false, 'c' => null]);
});

test('skips // and /* */ comments', function () {
    $src = "{ // line comment\n /* block\n comment */ a:1, b:2 }";
    $out = Json5Decoder::decode($src);
    expect($out)->toBe(['a' => 1, 'b' => 2]);
});

test('rejects truly malformed input', function () {
    expect(fn () => Json5Decoder::decode('{a:1'))
        ->toThrow(RuntimeException::class);
});

test('rejects trailing garbage after a complete value', function () {
    expect(fn () => Json5Decoder::decode('{a:1} junk'))
        ->toThrow(RuntimeException::class);
});

test('decodes empty objects and arrays', function () {
    expect(Json5Decoder::decode('{}'))->toBe([])
        ->and(Json5Decoder::decode('[]'))->toBe([]);
});
