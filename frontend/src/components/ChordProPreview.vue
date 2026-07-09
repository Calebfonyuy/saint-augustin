<script setup lang="ts">
// Phase-1 ChordPro preview — just enough to show `[G]` chord markers above
// their anchored lyric syllables and render `{start_of_*}` section labels.
// Full transposition, font sizing, and printable layout arrive with the
// Musician View in Phase 2 (SRS 3.2).
//
// The renderer is intentionally tolerant: anything it doesn't understand
// falls through as plain lyric text.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ source: string }>()

const { t } = useI18n()

interface ChordLyric {
  chords: string
  lyrics: string
}
interface Section {
  label: string | null
  lines: ChordLyric[]
}

function splitChordLine(line: string): ChordLyric {
  // Walk characters, keeping a chord row that stays column-aligned with the
  // lyric row below. A chord like [G] is emitted at the current column and
  // the lyric column advances past it without producing any lyric chars.
  let chords = ''
  let lyrics = ''
  let i = 0
  while (i < line.length) {
    if (line[i] === '[') {
      const end = line.indexOf(']', i + 1)
      if (end === -1) {
        // malformed — treat as lyric
        lyrics += line[i]
        chords += ' '
        i += 1
        continue
      }
      const chord = line.slice(i + 1, end)
      // Pad chord row so the chord token starts at the current lyric column.
      if (chords.length < lyrics.length) chords += ' '.repeat(lyrics.length - chords.length)
      chords += chord
      // The chord occupies no space on the lyric row, so a chord that's
      // wider than the lyric-run that follows simply bleeds right.
      i = end + 1
    } else {
      lyrics += line[i]
      i += 1
    }
  }
  // Right-pad chord row to match lyric row so `white-space: pre` aligns.
  if (chords.length < lyrics.length) chords += ' '.repeat(lyrics.length - chords.length)
  return { chords, lyrics }
}

const sections = computed<Section[]>(() => {
  const out: Section[] = []
  let current: Section = { label: null, lines: [] }
  const lines = props.source.split(/\r?\n/)
  for (const raw of lines) {
    const line = raw.trimEnd()
    const directive = line.match(/^\{(start_of_[a-z]+|soc|sov|sob|sot|comment|c)(?::\s*(.*))?\}$/i)
    if (directive) {
      if (current.lines.length || current.label) {
        out.push(current)
      }
      current = { label: directive[2] ?? directive[1].replace(/^start_of_/, ''), lines: [] }
      continue
    }
    if (/^\{(end_of_[a-z]+|eoc|eov|eob|eot)\}$/i.test(line)) {
      if (current.lines.length || current.label) out.push(current)
      current = { label: null, lines: [] }
      continue
    }
    if (!line) {
      if (current.lines.length) {
        out.push(current)
        current = { label: null, lines: [] }
      }
      continue
    }
    current.lines.push(splitChordLine(line))
  }
  if (current.lines.length || current.label) out.push(current)
  return out
})
</script>

<template>
  <div v-if="sections.length === 0" class="text-text-faint text-sm italic">
    {{ t('chordPro.empty') }}
  </div>
  <div v-else class="space-y-7">
    <section v-for="(section, i) in sections" :key="i">
      <div
        v-if="section.label"
        class="mono inline-block uppercase tracking-[0.16em] text-[10.5px] text-text-faint border border-border rounded px-2 py-[2px] mb-2"
      >
        {{ section.label }}
      </div>
      <div>
        <div v-for="(ln, j) in section.lines" :key="j" class="mb-1">
          <div v-if="ln.chords.trim()" class="chordline text-[13px]">{{ ln.chords }}</div>
          <div class="lyricline text-[17px] leading-[1.4]">{{ ln.lyrics }}</div>
        </div>
      </div>
    </section>
  </div>
</template>
