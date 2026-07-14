<?php

namespace App\Http\Controllers\Concerns;

/**
 * Strips path-unsafe characters from a user-supplied name (playlist/songbook)
 * for use in a Content-Disposition filename. Shared by the export controllers.
 */
trait SanitizesFilename
{
    protected function safeFilename(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9 _\-]/', '', $name) ?? '';
        $clean = trim(preg_replace('/\s+/', '-', $clean) ?? '');

        return $clean !== '' ? $clean : 'export';
    }
}
