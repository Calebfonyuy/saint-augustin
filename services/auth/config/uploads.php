<?php

/*
 * SaintAugustin Auth Service — Upload size knobs
 *
 * Single source of truth for the Laravel-side validator caps on file
 * uploads. The PHP engine itself enforces a hard ceiling via the
 * `upload_max_filesize` and `post_max_size` directives (see
 * docker/php/uploads.ini) — those have to be bumped IN PARALLEL with the
 * values here, or PHP will reject the request before Laravel ever runs
 * its validator.
 *
 * If you change these, also update docker/php/uploads.ini and rebuild the
 * auth-service container.
 *
 * Values are bytes so call sites can divide by 1024 for Laravel's `max:`
 * validation rule (which expects kilobytes).
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Bulk import (e.g. VideoPsalm .vpagd archives)
    |--------------------------------------------------------------------------
    | Used by SongImportController. The sample VideoPsalm bundle from the
    | parish is ~11 MB; 50 MB leaves room for larger libraries without
    | making the service a dumping ground.
    */
    'import_max_size_bytes' => (int) env('UPLOAD_IMPORT_MAX_MB', 100) * 1024 * 1024,

    /*
    |--------------------------------------------------------------------------
    | Song sheets (per-file PDF/image attachment, FR5)
    |--------------------------------------------------------------------------
    | Used by SongSheetController. A typical lead sheet is well under 1 MB;
    | 10 MB tolerates scanned PDFs and high-res photos of handwritten parts.
    */
    'sheet_max_size_bytes' => (int) env('UPLOAD_SHEET_MAX_MB', 10) * 1024 * 1024,

];
