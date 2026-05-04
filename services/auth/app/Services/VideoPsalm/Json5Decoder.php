<?php

namespace App\Services\VideoPsalm;

use RuntimeException;

/**
 * Tiny decoder for the VideoPsalm `.vpagd` archive's JSON-ish files.
 *
 * The files are not strict JSON — keys are unquoted (`{Guid:"..."}`) and
 * string literals can contain raw line breaks. Both habits are illegal in
 * RFC 8259 JSON and even in JSON5. PHP's built-in `json_decode` therefore
 * refuses them outright, so we hand-roll a recursive-descent parser that
 * accepts the small superset VideoPsalm uses.
 *
 * Grammar (informal):
 *   value   := object | array | string | number | bool | null
 *   object  := '{' [ pair (',' pair)* ] '}'
 *   pair    := key ':' value
 *   key     := identifier | string
 *   array   := '[' [ value (',' value)* ] ']'
 *   string  := '"' chars '"'      (chars may include \", \\, \n, \t, raw \n)
 *   number  := -? digits ('.' digits)? (exponent)?
 *
 * A trailing comma before `]` or `}` is tolerated to be lenient with files
 * produced by older VideoPsalm releases.
 *
 * The decoder is intentionally non-allocating beyond the result tree — it
 * keeps a single cursor into the source string. With ~400 ~1KB songs in the
 * sample file, the whole archive parses in a few milliseconds.
 */
final class Json5Decoder
{
    private string $src;
    private int $pos;
    private int $len;

    public static function decode(string $input): mixed
    {
        $d = new self($input);
        $d->skipWs();
        $value = $d->readValue();
        $d->skipWs();
        if ($d->pos !== $d->len) {
            throw new RuntimeException("Unexpected trailing content at offset {$d->pos}.");
        }
        return $value;
    }

    private function __construct(string $input)
    {
        $this->src = $input;
        $this->pos = 0;
        $this->len = strlen($input);
    }

    private function readValue(): mixed
    {
        $this->skipWs();
        if ($this->pos >= $this->len) {
            throw new RuntimeException('Unexpected end of input.');
        }
        $c = $this->src[$this->pos];

        if ($c === '{') {
            return $this->readObject();
        }
        if ($c === '[') {
            return $this->readArray();
        }
        if ($c === '"') {
            return $this->readString();
        }
        if ($c === '-' || ($c >= '0' && $c <= '9')) {
            return $this->readNumber();
        }
        // Bare identifier — bool / null. (We don't see these in VPAGD, but
        // it's cheap to support.)
        $word = $this->readIdent();
        if ($word === 'true')  return true;
        if ($word === 'false') return false;
        if ($word === 'null')  return null;
        throw new RuntimeException("Unexpected token '{$word}' at offset {$this->pos}.");
    }

    /** @return array<string, mixed> */
    private function readObject(): array
    {
        $this->expect('{');
        $out = [];
        $this->skipWs();
        if ($this->peek() === '}') {
            $this->pos++;
            return $out;
        }
        while (true) {
            $this->skipWs();
            $key = $this->peek() === '"' ? $this->readString() : $this->readIdent();
            if ($key === '') {
                throw new RuntimeException("Expected object key at offset {$this->pos}.");
            }
            $this->skipWs();
            $this->expect(':');
            $out[$key] = $this->readValue();
            $this->skipWs();
            $next = $this->peek();
            if ($next === ',') {
                $this->pos++;
                $this->skipWs();
                // Tolerate trailing comma.
                if ($this->peek() === '}') {
                    $this->pos++;
                    return $out;
                }
                continue;
            }
            if ($next === '}') {
                $this->pos++;
                return $out;
            }
            throw new RuntimeException("Expected ',' or '}' at offset {$this->pos}, got '" . ($next ?? 'EOF') . "'.");
        }
    }

    /** @return list<mixed> */
    private function readArray(): array
    {
        $this->expect('[');
        $out = [];
        $this->skipWs();
        if ($this->peek() === ']') {
            $this->pos++;
            return $out;
        }
        while (true) {
            $out[] = $this->readValue();
            $this->skipWs();
            $next = $this->peek();
            if ($next === ',') {
                $this->pos++;
                $this->skipWs();
                if ($this->peek() === ']') {
                    $this->pos++;
                    return $out;
                }
                continue;
            }
            if ($next === ']') {
                $this->pos++;
                return $out;
            }
            throw new RuntimeException("Expected ',' or ']' at offset {$this->pos}, got '" . ($next ?? 'EOF') . "'.");
        }
    }

    /**
     * Reads a `"…"` string. Escape sequences accepted: \" \\ \/ \n \r \t \b \f \uXXXX.
     * Raw newline / tab characters inside the literal are preserved verbatim
     * (this is the key divergence from JSON).
     */
    private function readString(): string
    {
        $this->expect('"');
        $out = '';
        while ($this->pos < $this->len) {
            $c = $this->src[$this->pos];
            if ($c === '"') {
                $this->pos++;
                return $out;
            }
            if ($c === '\\') {
                $this->pos++;
                if ($this->pos >= $this->len) {
                    throw new RuntimeException('Unterminated string escape.');
                }
                $esc = $this->src[$this->pos++];
                $out .= match ($esc) {
                    '"', '\\', '/' => $esc,
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'b' => "\x08",
                    'f' => "\x0C",
                    'u' => $this->readUnicodeEscape(),
                    default => $esc,
                };
                continue;
            }
            $out .= $c;
            $this->pos++;
        }
        throw new RuntimeException('Unterminated string literal.');
    }

    private function readUnicodeEscape(): string
    {
        if ($this->pos + 4 > $this->len) {
            throw new RuntimeException('Truncated \u escape.');
        }
        $hex = substr($this->src, $this->pos, 4);
        if (! ctype_xdigit($hex)) {
            throw new RuntimeException("Invalid \\u escape '{$hex}'.");
        }
        $this->pos += 4;
        $cp = hexdec($hex);
        return mb_chr($cp, 'UTF-8') ?: '';
    }

    private function readNumber(): int|float
    {
        $start = $this->pos;
        if ($this->peek() === '-') {
            $this->pos++;
        }
        while ($this->pos < $this->len && ctype_digit($this->src[$this->pos])) {
            $this->pos++;
        }
        $isFloat = false;
        if ($this->peek() === '.') {
            $isFloat = true;
            $this->pos++;
            while ($this->pos < $this->len && ctype_digit($this->src[$this->pos])) {
                $this->pos++;
            }
        }
        if ($this->peek() === 'e' || $this->peek() === 'E') {
            $isFloat = true;
            $this->pos++;
            if ($this->peek() === '+' || $this->peek() === '-') {
                $this->pos++;
            }
            while ($this->pos < $this->len && ctype_digit($this->src[$this->pos])) {
                $this->pos++;
            }
        }
        $raw = substr($this->src, $start, $this->pos - $start);
        return $isFloat ? (float) $raw : (int) $raw;
    }

    private function readIdent(): string
    {
        $start = $this->pos;
        while ($this->pos < $this->len) {
            $c = $this->src[$this->pos];
            if (ctype_alnum($c) || $c === '_' || $c === '$') {
                $this->pos++;
                continue;
            }
            break;
        }
        return substr($this->src, $start, $this->pos - $start);
    }

    private function skipWs(): void
    {
        while ($this->pos < $this->len) {
            $c = $this->src[$this->pos];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $this->pos++;
                continue;
            }
            // VideoPsalm files don't use comments, but tolerate `// ...` and
            // `/* ... */` so anyone hand-editing one doesn't blow us up.
            if ($c === '/' && $this->pos + 1 < $this->len) {
                $next = $this->src[$this->pos + 1];
                if ($next === '/') {
                    $this->pos += 2;
                    while ($this->pos < $this->len && $this->src[$this->pos] !== "\n") {
                        $this->pos++;
                    }
                    continue;
                }
                if ($next === '*') {
                    $this->pos += 2;
                    while ($this->pos + 1 < $this->len
                        && ! ($this->src[$this->pos] === '*' && $this->src[$this->pos + 1] === '/')) {
                        $this->pos++;
                    }
                    $this->pos += 2;
                    continue;
                }
            }
            break;
        }
    }

    private function peek(): ?string
    {
        return $this->pos < $this->len ? $this->src[$this->pos] : null;
    }

    private function expect(string $ch): void
    {
        if ($this->pos >= $this->len || $this->src[$this->pos] !== $ch) {
            $got = $this->peek() ?? 'EOF';
            throw new RuntimeException("Expected '{$ch}' at offset {$this->pos}, got '{$got}'.");
        }
        $this->pos++;
    }
}
