<script setup lang="ts">
/*
 * Inline song-sheet viewer.
 *
 * For PDFs we use a plain <iframe> — every modern browser ships a built-in
 * PDF viewer, which avoids pulling in the ~500KB of pdfjs-dist for what is
 * essentially a passive "show this scan" use case. If we ever need rich
 * features (annotation, page extraction) we can swap to pdfjs-dist without
 * changing this component's external surface.
 *
 * For images we just use <img>.
 *
 * If the presigned URL has expired the parent component is responsible for
 * calling `sheets.refresh(id)` and re-passing the new sheet — this viewer
 * doesn't try to recover transparently.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { SongSheet } from '@/types'
import Icon from './Icon.vue'

const props = defineProps<{ sheet: SongSheet }>()

const { t } = useI18n()

const isPdf = computed(() => props.sheet.file_type === 'pdf')
const isImage = computed(() => props.sheet.file_type === 'image')

// PDF embed URL: hide the toolbar so the viewer feels integrated rather than
// like a separate document. Browsers vary in support but it's a no-op
// elsewhere.
const pdfSrc = computed(() => `${props.sheet.url}#toolbar=0&navpanes=0`)
</script>

<template>
  <div class="flex flex-col" data-testid="sheet-viewer">
    <div class="flex items-center gap-2 px-3 py-2 border-b border-border">
      <Icon :name="isPdf ? 'list' : 'eye'" />
      <div class="text-[12px] truncate flex-1" :title="sheet.original_filename">
        {{ sheet.original_filename }}
      </div>
      <a
        :href="sheet.url"
        target="_blank"
        rel="noopener"
        class="text-[11px] text-text-faint hover:text-accent"
      >
        {{ t('sheetViewer.open') }}
      </a>
    </div>
    <div class="bg-bg-sunken min-h-[280px] flex-1">
      <iframe
        v-if="isPdf"
        :src="pdfSrc"
        class="w-full h-full block"
        style="min-height: 320px; border: 0"
        :title="sheet.original_filename"
        data-testid="sheet-pdf"
      />
      <img
        v-else-if="isImage"
        :src="sheet.url"
        :alt="sheet.original_filename"
        class="w-full h-auto block"
        data-testid="sheet-image"
      />
      <div v-else class="p-4 text-text-faint text-[13px]">
        {{ t('sheetViewer.unsupported') }}
      </div>
    </div>
  </div>
</template>
