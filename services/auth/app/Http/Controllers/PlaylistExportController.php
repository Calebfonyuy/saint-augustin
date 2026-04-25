<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use App\Models\PlaylistItem;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Playlist export (SRS 3.3 / 7.1: GET /api/playlists/:id/export?format=pdf|txt).
 *
 * Authorization: any authenticated user can export any playlist they can
 * already see. The shape is a simple song list (title, author, key) per
 * the SRS — no chords, no lyrics, no notes. PDF rendering is delegated to
 * DomPDF via a Blade template.
 */
class PlaylistExportController
{
    public function __invoke(Request $request, string $id): HttpResponse
    {
        $playlist = Playlist::with(['items.song'])->find($id);

        if (! $playlist) {
            return response()->json(['message' => 'Playlist not found.'], 404);
        }

        $format = strtolower($request->query('format', 'pdf'));

        return match ($format) {
            'pdf' => $this->renderPdf($playlist),
            'txt' => $this->renderText($playlist),
            default => response()->json([
                'message' => 'Unsupported format. Use pdf or txt.',
            ], 422),
        };
    }

    private function renderPdf(Playlist $playlist): HttpResponse
    {
        $pdf = Pdf::loadView('playlist-export', [
            'playlist' => $playlist,
            'items'    => $playlist->items, // already ordered by position via the relationship
        ]);

        $filename = $this->safeFilename($playlist->name).'.pdf';

        return $pdf->download($filename);
    }

    private function renderText(Playlist $playlist): Response
    {
        $lines = [];
        $lines[] = $playlist->name;

        if ($playlist->event_date) {
            $lines[] = $playlist->event_date->format('l, F j, Y');
        }

        $lines[] = str_repeat('=', max(strlen($playlist->name), 20));
        $lines[] = '';

        if ($playlist->items->isEmpty()) {
            $lines[] = '(no songs)';
        } else {
            foreach ($playlist->items as $idx => $item) {
                $lines[] = $this->formatTextLine($idx + 1, $item);
            }
        }

        $lines[] = '';
        $lines[] = '— SaintAugustin · '.now()->format('Y-m-d H:i');

        $body = implode("\n", $lines)."\n";
        $filename = $this->safeFilename($playlist->name).'.txt';

        return response($body, 200, [
            'Content-Type'        => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function formatTextLine(int $n, PlaylistItem $item): string
    {
        $song = $item->song;
        $title = $song?->title ?? '(deleted song)';
        $author = $song?->author ? ' — '.$song->author : '';

        $key = $item->target_key
            ?? $song?->original_key
            ?? '?';

        return sprintf('%2d. %s%s   [%s]', $n, $title, $author, $key);
    }

    /**
     * Strip path-unsafe characters from a playlist name for use in a
     * Content-Disposition filename. Falls back to "playlist" if the
     * sanitized result is empty.
     */
    private function safeFilename(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9 _\-]/', '', $name) ?? '';
        $clean = trim(preg_replace('/\s+/', '-', $clean) ?? '');

        return $clean !== '' ? $clean : 'playlist';
    }
}
