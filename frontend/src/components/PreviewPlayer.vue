<script setup lang="ts">
/*
 * Song preview player.
 *
 * Two modes:
 *   • YouTube URLs → embedded YouTube iframe. We accept the standard
 *     watch?v=, youtu.be, and /embed/ shapes.
 *   • Anything that looks like a direct audio file (.mp3 / .ogg / .wav /
 *     .m4a) → HTML5 <audio>. Anything else is rendered as a generic link
 *     so the user can still click through.
 *
 * We deliberately don't try to embed Vimeo, SoundCloud, etc. yet — the SRS
 * mentions YouTube specifically and adding more providers means thinking
 * about CSP/iframe-src policy implications.
 */
import { computed } from 'vue'

const props = defineProps<{ url: string | null | undefined }>()

const trimmed = computed(() => props.url?.trim() ?? '')

/**
 * Pull a YouTube video ID out of any of the common URL shapes.
 * Returns `null` if the input doesn't look like YouTube.
 */
function youtubeId(raw: string): string | null {
  if (!raw) return null
  // Order matters: youtu.be then watch?v= then /embed/
  const patterns = [
    /youtu\.be\/([A-Za-z0-9_-]{6,})/,
    /[?&]v=([A-Za-z0-9_-]{6,})/,
    /youtube\.com\/embed\/([A-Za-z0-9_-]{6,})/,
    /youtube\.com\/shorts\/([A-Za-z0-9_-]{6,})/,
  ]
  for (const re of patterns) {
    const m = re.exec(raw)
    if (m) return m[1]
  }
  return null
}

const ytId = computed(() => youtubeId(trimmed.value))

const audioExtRe = /\.(mp3|ogg|wav|m4a)(?:[?#]|$)/i
const isAudio = computed(() => !ytId.value && audioExtRe.test(trimmed.value))

const embedSrc = computed(() => (ytId.value ? `https://www.youtube.com/embed/${ytId.value}` : ''))
</script>

<template>
  <div v-if="trimmed" data-testid="preview-player" class="card overflow-hidden">
    <div v-if="ytId" class="aspect-video bg-black">
      <iframe
        :src="embedSrc"
        class="w-full h-full block"
        style="border: 0"
        title="Song preview"
        loading="lazy"
        allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
        allowfullscreen
        data-testid="preview-youtube"
      />
    </div>
    <audio
      v-else-if="isAudio"
      :src="trimmed"
      controls
      preload="none"
      class="w-full"
      data-testid="preview-audio"
    />
    <a
      v-else
      :href="trimmed"
      target="_blank"
      rel="noopener"
      class="block p-3 text-[13px] text-accent underline truncate"
      data-testid="preview-link"
    >
      {{ trimmed }}
    </a>
  </div>
</template>
