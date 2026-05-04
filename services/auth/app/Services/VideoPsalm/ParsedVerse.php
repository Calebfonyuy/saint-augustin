<?php

namespace App\Services\VideoPsalm;

final readonly class ParsedVerse
{
    public function __construct(
        public string $text,
        public bool $isChorus,
    ) {}
}
