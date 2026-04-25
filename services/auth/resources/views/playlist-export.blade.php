<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ $playlist->name }}</title>
    <style>
        @page { margin: 1.5cm 1.8cm; }
        body { font-family: DejaVu Sans, sans-serif; color: #222; font-size: 12pt; }
        h1 { font-size: 22pt; margin: 0 0 4pt 0; }
        .meta { color: #666; font-size: 10.5pt; margin-bottom: 18pt; }
        .item { padding: 8pt 0; border-bottom: 1px solid #e5e5e5; }
        .row { display: table; width: 100%; }
        .num { display: table-cell; width: 28pt; color: #888; vertical-align: top; }
        .title { display: table-cell; vertical-align: top; }
        .song-title { font-size: 13pt; font-weight: bold; }
        .author { color: #666; font-size: 10.5pt; }
        .key { display: table-cell; width: 90pt; text-align: right; vertical-align: top; }
        .key-badge {
            display: inline-block; padding: 2pt 6pt;
            border: 1px solid #999; border-radius: 4pt;
            font-family: monospace; font-size: 11pt;
        }
        .key-original { color: #888; font-size: 10pt; margin-left: 4pt; }
        .notes { color: #444; font-size: 10pt; margin-top: 4pt; padding-left: 28pt; }
        .deleted { color: #c00; font-style: italic; }
        .footer {
            position: fixed; bottom: -0.8cm; left: 0; right: 0;
            text-align: center; color: #999; font-size: 9pt;
        }
    </style>
</head>
<body>
    <h1>{{ $playlist->name }}</h1>
    <div class="meta">
        @if ($playlist->event_date)
            {{ $playlist->event_date->format('l, F j, Y') }} &middot;
        @endif
        {{ count($items) }} {{ count($items) === 1 ? 'song' : 'songs' }}
        @if (count($playlist->tags ?? []))
            &middot; tags: {{ implode(', ', $playlist->tags) }}
        @endif
    </div>

    @forelse ($items as $idx => $item)
        <div class="item">
            <div class="row">
                <div class="num">{{ $idx + 1 }}.</div>
                <div class="title">
                    <div class="song-title{{ $item->song?->trashed() ? ' deleted' : '' }}">
                        {{ $item->song?->title ?? '(deleted song)' }}
                    </div>
                    @if ($item->song?->author)
                        <div class="author">{{ $item->song->author }}</div>
                    @endif
                </div>
                <div class="key">
                    @if ($item->target_key)
                        <span class="key-badge">{{ $item->target_key }}</span>
                        @if ($item->song?->original_key && $item->song->original_key !== $item->target_key)
                            <span class="key-original">(orig {{ $item->song->original_key }})</span>
                        @endif
                    @elseif ($item->song?->original_key)
                        <span class="key-badge">{{ $item->song->original_key }}</span>
                    @endif
                </div>
            </div>
            @if ($item->notes)
                <div class="notes">{{ $item->notes }}</div>
            @endif
        </div>
    @empty
        <div class="item"><em>(no songs)</em></div>
    @endforelse

    <div class="footer">SaintAugustin &middot; {{ now()->format('Y-m-d H:i') }}</div>
</body>
</html>
