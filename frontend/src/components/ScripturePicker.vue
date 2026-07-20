<script setup lang="ts">
// Graphical scripture reference picker (FR-BI-4): book → chapter → verse,
// with an optional "to" end point for a range. Books come from the Bible
// store's per-translation cache. Emits discrete reference params up to the
// parent (AddReadingDialog), which resolves + stores them.
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useBibleStore } from '@/stores/bible'
import type { BibleBook } from '@/types'

const props = defineProps<{ translationId: string | null }>()

const emit = defineEmits<{
  (e: 'change', ref: {
    book_code: string
    start_chapter: number
    start_verse: number
    end_chapter: number | null
    end_verse: number | null
  } | null): void
}>()

const { t } = useI18n()
const bible = useBibleStore()

const books = ref<BibleBook[]>([])
const bookCode = ref('')
const startChapter = ref(1)
const startVerse = ref(1)
const showRange = ref(false)
const endChapter = ref(1)
const endVerse = ref(1)

const selectedBook = computed(() => books.value.find((b) => b.book_code === bookCode.value) ?? null)
const chapterCount = computed(() => selectedBook.value?.chapter_count ?? 1)

watch(
  () => props.translationId,
  async (id) => {
    books.value = id ? await bible.ensureBooks(id) : []
    if (!books.value.some((b) => b.book_code === bookCode.value)) {
      bookCode.value = books.value[0]?.book_code ?? ''
    }
    emitChange()
  },
  { immediate: true },
)

watch([bookCode, startChapter, startVerse, showRange, endChapter, endVerse], emitChange)

function emitChange(): void {
  if (!bookCode.value) {
    emit('change', null)
    return
  }
  emit('change', {
    book_code: bookCode.value,
    start_chapter: startChapter.value,
    start_verse: startVerse.value,
    end_chapter: showRange.value ? endChapter.value : null,
    end_verse: showRange.value ? endVerse.value : null,
  })
}
</script>

<template>
  <div class="flex flex-col gap-2" data-testid="scripture-picker">
    <select v-model="bookCode" class="input" data-testid="picker-book">
      <option v-for="b in books" :key="b.book_code" :value="b.book_code">{{ b.name }}</option>
    </select>

    <div class="flex items-center gap-2">
      <select v-model.number="startChapter" class="input" style="max-width: 90px" data-testid="picker-chapter">
        <option v-for="c in chapterCount" :key="c" :value="c">{{ c }}</option>
      </select>
      <span class="text-text-faint">:</span>
      <input
        v-model.number="startVerse"
        type="number"
        min="1"
        class="input"
        style="max-width: 90px"
        data-testid="picker-verse"
      />
      <label class="flex items-center gap-1 text-[12px] text-text-muted ml-2">
        <input v-model="showRange" type="checkbox" data-testid="picker-range" />
        {{ t('addReading.range') }}
      </label>
    </div>

    <div v-if="showRange" class="flex items-center gap-2">
      <span class="text-[12px] text-text-faint">{{ t('addReading.to') }}</span>
      <input v-model.number="endChapter" type="number" min="1" class="input" style="max-width: 90px" data-testid="picker-end-chapter" />
      <span class="text-text-faint">:</span>
      <input v-model.number="endVerse" type="number" min="1" class="input" style="max-width: 90px" data-testid="picker-end-verse" />
    </div>
  </div>
</template>
