#!/usr/bin/env python3
"""
Generate the sample PDF lead sheets used by SongSheetSeeder.

This is a one-shot generator: run it once to (re)create the files in
services/auth/storage/seed-sheets/. The output files are committed to the
repo so the seeder doesn't need any runtime PDF dependency.

We hand-roll the PDF bytes (stdlib only) because none of the obvious
alternatives - reportlab, weasyprint, dompdf - are guaranteed to be
installed on a contributor's machine. The generated files are minimal but
valid PDFs that every modern viewer (Chrome, Firefox, Acrobat) renders
without complaint.
"""

from __future__ import annotations
import os
from pathlib import Path

OUT_DIR = Path(__file__).resolve().parent.parent / "services" / "auth" / "storage" / "seed-sheets"


def _escape(text: str) -> str:
    """Escape characters that have special meaning inside a PDF text string."""
    return text.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")


def make_pdf(title: str, subtitle: str, body_lines: list[str]) -> bytes:
    """
    Build a one-page Letter-sized PDF with a title, subtitle, and a body
    block. Layout is fixed (no wrapping) - keep body lines short.
    """
    # Build the page-content stream first because we need its length.
    parts: list[str] = ["BT"]
    # Title (24pt, anchored ~1in from top of an 11in page).
    parts.append("/F1 24 Tf")
    parts.append(f"72 720 Td ({_escape(title)}) Tj")
    # Subtitle (12pt, just below title).
    parts.append("/F1 12 Tf")
    parts.append(f"0 -28 Td ({_escape(subtitle)}) Tj")
    # Body lines (12pt mono-feel via Courier; 18pt leading).
    parts.append("/F2 11 Tf")
    parts.append("0 -32 Td")
    for i, line in enumerate(body_lines):
        if i > 0:
            parts.append("0 -16 Td")
        parts.append(f"({_escape(line)}) Tj")
    parts.append("ET")
    stream = "\n".join(parts).encode("latin-1")

    # Each object is built as bytes so xref offsets line up exactly.
    objects: list[bytes] = []

    objects.append(b"<</Type/Catalog/Pages 2 0 R>>")
    objects.append(b"<</Type/Pages/Kids[3 0 R]/Count 1>>")
    objects.append(
        b"<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]"
        b"/Contents 4 0 R"
        b"/Resources<</Font<<"
        b"/F1<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>"
        b"/F2<</Type/Font/Subtype/Type1/BaseFont/Courier>>"
        b">>>>>>"
    )
    objects.append(
        b"<</Length " + str(len(stream)).encode("ascii") + b">>\n"
        b"stream\n" + stream + b"\nendstream"
    )

    # Assemble the file. Track byte offsets for the xref table.
    out = bytearray()
    out += b"%PDF-1.4\n"
    # Binary marker (best practice - keeps tools from misclassifying as text).
    out += b"%\xe2\xe3\xcf\xd3\n"

    offsets: list[int] = []
    for i, body in enumerate(objects, start=1):
        offsets.append(len(out))
        out += f"{i} 0 obj\n".encode("ascii")
        out += body
        out += b"\nendobj\n"

    xref_offset = len(out)
    out += f"xref\n0 {len(objects) + 1}\n".encode("ascii")
    out += b"0000000000 65535 f \n"
    for off in offsets:
        out += f"{off:010d} 00000 n \n".encode("ascii")

    out += f"trailer\n<</Size {len(objects) + 1}/Root 1 0 R>>\n".encode("ascii")
    out += f"startxref\n{xref_offset}\n%%EOF\n".encode("ascii")

    return bytes(out)


SHEETS = [
    {
        "filename": "amazing-grace-lead-sheet.pdf",
        "title": "Amazing Grace",
        "subtitle": "Lead sheet - John Newton (1779)  |  Key of G",
        "body": [
            "Verse 1",
            "  G          G7        C            G",
            "  Amazing grace, how sweet the sound",
            "  G            Em        D",
            "  That saved a wretch like me",
            "  G          G7      C           G",
            "  I once was lost, but now am found",
            "  Em      D        G",
            "  Was blind but now I see",
            "",
            "Verse 2",
            "  'Twas grace that taught my heart to fear",
            "  And grace my fears relieved",
            "  How precious did that grace appear",
            "  The hour I first believed",
        ],
    },
    {
        "filename": "holy-holy-holy-choir-part.pdf",
        "title": "Holy, Holy, Holy",
        "subtitle": "Choir part - Reginald Heber  |  Key of D, 4/4",
        "body": [
            "SOPRANO",
            "  D            A         D            G",
            "  Holy, holy, holy! Lord God Almighty!",
            "  A              D       A              D",
            "  Early in the morning our song shall rise to Thee",
            "",
            "ALTO",
            "  Holy, holy, holy! Merciful and mighty!",
            "  God in three persons, blessed Trinity!",
            "",
            "Tempo: 96 bpm",
            "Time signature: 4/4",
        ],
    },
    {
        "filename": "how-great-thou-art-lead-sheet.pdf",
        "title": "How Great Thou Art",
        "subtitle": "Lead sheet - Stuart K. Hine  |  Key of Bb, 4/4",
        "body": [
            "Verse 1",
            "  Bb              Eb               Bb",
            "  O Lord my God! When I in awesome wonder",
            "  Bb         F                Bb",
            "  Consider all the worlds Thy hands have made",
            "  Bb              Eb              Bb",
            "  I see the stars, I hear the rolling thunder",
            "  Bb         F            Bb",
            "  Thy power throughout the universe displayed",
            "",
            "Chorus",
            "  Bb              Eb              Bb",
            "  Then sings my soul, my Saviour God, to Thee",
            "  Bb         F             Bb",
            "  How great Thou art, how great Thou art",
            "",
            "CCLI #14181",
        ],
    },
]


def main() -> int:
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    for sheet in SHEETS:
        path = OUT_DIR / sheet["filename"]
        data = make_pdf(sheet["title"], sheet["subtitle"], sheet["body"])
        path.write_bytes(data)
        print(f"  wrote {path.relative_to(OUT_DIR.parent.parent.parent.parent)} ({len(data)} bytes)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
