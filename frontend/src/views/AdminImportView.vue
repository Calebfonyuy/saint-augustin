<script setup lang="ts">
/*
 * Admin → Import / Export → VideoPsalm import.
 *
 * Two-stage UX:
 *   1. Pick a `.vpagd` file → we POST it with dry_run=1 and render the
 *      parsed song list (per-songbook counts + selectable rows). Nothing is
 *      written to the DB at this point.
 *   2. The admin trims the selection (or imports the lot) and clicks
 *      Import — we POST again, this time without dry_run and with the
 *      selected guids[]. The server returns counts of created/skipped songs.
 *
 * Re-uploading the file on commit is deliberate: caching parsed payloads
 * server-side adds storage and cleanup cost for what is at most an ~11 MB
 * blob used twice a year. The file is held in a single ref so the second
 * upload re-uses the original File object the admin already picked.
 *
 * The page is gated by `requiresAdmin` in the router, so we don't double-
 * check role here.
 */
import { computed, ref } from 'vue'
import AppShell from '@/components/AppShell.vue'
import AdminTabs from '@/components/admin/AdminTabs.vue'
import Icon from '@/components/Icon.vue'
import Toast from '@/components/Toast.vue'
import {
  importVideopsalm,
  previewVideopsalm,
  type VpagdImportResult,
  type VpagdPreview,
  type VpagdPreviewSong,
} from '@/api/imports'
import { extractErrorMessage } from '@/api/client'

// ── State ─────────────────────────────────────────────────────────────

const file = ref<File | null>(null)
const preview = ref<VpagdPreview | null>(null)
const result = ref<VpagdImportResult | null>(null)
const loading = ref<'idle' | 'parsing' | 'importing'>('idle')
const dragOver = ref(false)
const search = ref('')
const selected = ref<Set<string>>(new Set())
const fileInput = ref<HTMLInputElement | null>(null)
const toast = ref<{ message: string; kind: 'error' | 'success' } | null>(null)

// ── Derived ───────────────────────────────────────────────────────────

const filteredSongs = computed<VpagdPreviewSong[]>(() => {
  if (!preview.value) return []
  const q = search.value.trim().toLowerCase()
  if (!q) return preview.value.songs
  return preview.value.songs.filter(
    (s) => s.title.toLowerCase().includes(q) || s.songbook.toLowerCase().includes(q),
  )
})

const allSelected = computed(
  () =>
    filteredSongs.value.length > 0 &&
    filteredSongs.value.every((s) => selected.value.has(s.guid)),
)

const songbookEntries = computed<Array<[string, number]>>(() => {
  if (!preview.value) return []
  return Object.entries(preview.value.songbooks).sort((a, b) => b[1] - a[1])
})

const selectedCount = computed(() => selected.value.size)

// ── File handling ─────────────────────────────────────────────────────

function notify(kind: 'success' | 'error', message: string): void {
  toast.value = { message, kind }
}

function reset(): void {
  file.value = null
  preview.value = null
  result.value = null
  selected.value = new Set()
  search.value = ''
  if (fileInput.value) fileInput.value.value = ''
}

async function onFileSelected(picked: File): Promise<void> {
  if (!picked.name.toLowerCase().endsWith('.vpagd')) {
    notify('error', 'Please choose a VideoPsalm `.vpagd` file.')
    return
  }
  file.value = picked
  preview.value = null
  result.value = null
  selected.value = new Set()
  loading.value = 'parsing'
  try {
    const data = await previewVideopsalm(picked)
    preview.value = data
    // Default: every song selected — admins almost always want the full
    // import; making them tick 380 boxes would be punishing.
    selected.value = new Set(data.songs.map((s) => s.guid))
  } catch (err) {
    notify('error', extractErrorMessage(err, 'Could not parse archive.'))
    file.value = null
  } finally {
    loading.value = 'idle'
  }
}

function onPickClick(): void {
  fileInput.value?.click()
}

function onPickChange(e: Event): void {
  const input = e.target as HTMLInputElement
  const f = input.files?.[0]
  if (f) void onFileSelected(f)
}

function onDrop(e: DragEvent): void {
  e.preventDefault()
  dragOver.value = false
  const f = e.dataTransfer?.files?.[0]
  if (f) void onFileSelected(f)
}

// ── Selection ─────────────────────────────────────────────────────────

function toggleAll(): void {
  if (!preview.value) return
  if (allSelected.value) {
    const next = new Set(selected.value)
    for (const s of filteredSongs.value) next.delete(s.guid)
    selected.value = next
  } else {
    const next = new Set(selected.value)
    for (const s of filteredSongs.value) next.add(s.guid)
    selected.value = next
  }
}

function toggleOne(guid: string): void {
  const next = new Set(selected.value)
  if (next.has(guid)) next.delete(guid)
  else next.add(guid)
  selected.value = next
}

// ── Commit ────────────────────────────────────────────────────────────

async function onImport(): Promise<void> {
  if (!file.value || !preview.value) return
  if (selectedCount.value === 0) {
    notify('error', 'Select at least one song to import.')
    return
  }

  // If the user kept the default "everything" selection we send no `guids`
  // at all — it's a slightly smaller payload and matches the controller's
  // shortest path.
  const sendAll = selectedCount.value === preview.value.songs.length
  const guids = sendAll ? undefined : Array.from(selected.value)

  loading.value = 'importing'
  try {
    const r = await importVideopsalm(file.value, guids)
    result.value = r
    const verb = r.created === 1 ? 'song' : 'songs'
    notify('success', `Imported ${r.created} ${verb} (skipped ${r.skipped}).`)
  } catch (err) {
    notify('error', extractErrorMessage(err, 'Import failed.'))
  } finally {
    loading.value = 'idle'
  }
}

function startOver(): void {
  reset()
}
</script>

<template>
  <AppShell>
    <AdminTabs active="import" subtitle="Bulk song import" />

    <div class="px-6 py-5 overflow-auto flex-1">
      <!-- Stage 1: file picker (shown only when no archive is loaded yet) -->
      <section v-if="!preview && !result" data-testid="import-picker">
        <h2 class="font-display text-[18px] font-semibold mb-1">VideoPsalm import</h2>
        <p class="text-[13px] text-text-muted mb-4 max-w-[640px]">
          Upload a <span class="mono">.vpagd</span> archive exported from VideoPsalm. We'll
          parse it and show you the song list before anything is written to the database.
          Songs that already exist (matched on title within the same songbook) are skipped
          on import — no overwrites.
        </p>

        <div
          class="card p-10 text-center transition-colors"
          :class="dragOver ? 'border-accent bg-accent-soft' : ''"
          data-testid="import-dropzone"
          @dragover.prevent="dragOver = true"
          @dragleave.prevent="dragOver = false"
          @drop="onDrop"
        >
          <div class="flex justify-center mb-3 text-accent">
            <Icon name="upload" :size="28" />
          </div>
          <div class="text-[14px] mb-2">
            Drop your <span class="mono">.vpagd</span> archive here
          </div>
          <div class="text-[12px] text-text-faint mb-4">or</div>
          <button
            type="button"
            class="btn btn-primary"
            :disabled="loading === 'parsing'"
            data-testid="import-pick-btn"
            @click="onPickClick"
          >
            {{ loading === 'parsing' ? 'Parsing…' : 'Choose file' }}
          </button>
          <input
            ref="fileInput"
            type="file"
            accept=".vpagd"
            class="hidden"
            data-testid="import-file-input"
            @change="onPickChange"
          />
          <div class="mt-3 text-[11px] text-text-faint">
            Maximum size: 50 MB. Admin only.
          </div>
        </div>
      </section>

      <!-- Stage 3: post-import success card -->
      <section v-else-if="result" data-testid="import-result">
        <div class="card p-6 max-w-[560px]">
          <div class="flex items-center gap-2 mb-3 text-accent">
            <Icon name="check" :size="20" />
            <h2 class="font-display text-[18px] font-semibold m-0">Import complete</h2>
          </div>

          <div
            class="grid gap-3 my-4"
            style="grid-template-columns: repeat(3, 1fr)"
          >
            <div class="card p-[14px]">
              <div class="mono uppercase tracking-[0.14em] text-[11px] text-text-faint">
                Created
              </div>
              <div class="font-display text-[28px] font-semibold mt-[6px]">
                {{ result.created }}
              </div>
            </div>
            <div class="card p-[14px]">
              <div class="mono uppercase tracking-[0.14em] text-[11px] text-text-faint">
                Skipped
              </div>
              <div class="font-display text-[28px] font-semibold mt-[6px]">
                {{ result.skipped }}
              </div>
            </div>
            <div class="card p-[14px]">
              <div class="mono uppercase tracking-[0.14em] text-[11px] text-text-faint">
                Total
              </div>
              <div class="font-display text-[28px] font-semibold mt-[6px]">
                {{ result.total }}
              </div>
            </div>
          </div>

          <div v-if="result.songbooks.length" class="text-[13px] mb-4">
            <div class="mono uppercase tracking-[0.14em] text-[11px] text-text-faint mb-2">
              Songbooks touched
            </div>
            <div class="flex flex-wrap gap-1">
              <span v-for="sb in result.songbooks" :key="sb" class="chip" style="font-size: 11px">
                {{ sb }}
              </span>
            </div>
          </div>

          <p v-if="result.skipped > 0" class="text-[12px] text-text-muted mb-4">
            {{ result.skipped }} song(s) were skipped because a song with the same title
            already exists in the same songbook. Existing edits are preserved.
          </p>

          <div class="flex gap-2">
            <button type="button" class="btn btn-primary" @click="startOver">
              Import another archive
            </button>
            <RouterLink to="/library" class="btn btn-ghost">View library</RouterLink>
          </div>
        </div>
      </section>

      <!-- Stage 2: preview / select / commit -->
      <section v-else-if="preview" data-testid="import-preview">
        <div class="flex items-baseline gap-3 mb-1 flex-wrap">
          <h2 class="font-display text-[18px] font-semibold">{{ file?.name }}</h2>
          <span class="text-[12px] text-text-faint">
            {{ preview.songs.length }} song(s) parsed
          </span>
          <button
            type="button"
            class="text-accent text-[12px] hover:underline ml-auto"
            @click="startOver"
          >
            Choose a different file
          </button>
        </div>

        <p class="text-[13px] text-text-muted mb-4 max-w-[640px]">
          Review the songs below and uncheck any you don't want to import. Songs already in
          the library (matched on title within the same songbook) will be skipped automatically.
        </p>

        <!-- Per-songbook counts -->
        <div
          class="grid gap-3 mb-5"
          style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr))"
          data-testid="import-songbook-counts"
        >
          <div v-for="[name, count] in songbookEntries" :key="name" class="card p-[12px]">
            <div class="mono uppercase tracking-[0.14em] text-[11px] text-text-faint truncate">
              {{ name }}
            </div>
            <div class="font-display text-[22px] font-semibold mt-[2px]">{{ count }}</div>
          </div>
        </div>

        <!-- Search + bulk bar -->
        <div class="flex gap-[10px] items-center mb-3 flex-wrap">
          <div class="relative flex-1 max-w-[360px]">
            <span class="absolute left-[10px] top-[10px] text-text-faint">
              <Icon name="search" />
            </span>
            <input
              v-model="search"
              class="input"
              placeholder="Search title or songbook…"
              style="padding-left: 30px"
              data-testid="import-search"
            />
          </div>

          <div class="text-[12px] text-text-muted">
            {{ selectedCount }} of {{ preview.songs.length }} selected
          </div>

          <div class="flex-1" />

          <button
            type="button"
            class="btn btn-primary"
            :disabled="loading === 'importing' || selectedCount === 0"
            data-testid="import-commit-btn"
            @click="onImport"
          >
            {{ loading === 'importing' ? 'Importing…' : `Import ${selectedCount} song(s)` }}
          </button>
        </div>

        <!-- Songs table -->
        <div class="card" data-testid="import-songs-table">
          <div
            class="grid items-center px-4 py-3 border-b border-border mono uppercase tracking-[0.14em] text-[10px] text-text-faint"
            style="grid-template-columns: 36px 3fr 2fr 80px"
          >
            <input
              type="checkbox"
              :checked="allSelected"
              :aria-label="allSelected ? 'Deselect all' : 'Select all'"
              data-testid="import-select-all"
              @change="toggleAll"
            />
            <div>Title</div>
            <div>Songbook</div>
            <div class="text-right">Verses</div>
          </div>

          <div
            v-for="(s, i) in filteredSongs"
            :key="s.guid"
            class="grid items-center px-4 py-3 hover:bg-bg-sunken cursor-pointer"
            :class="i < filteredSongs.length - 1 ? 'border-b border-border' : ''"
            style="grid-template-columns: 36px 3fr 2fr 80px; font-size: 13px"
            :data-testid="`import-row-${s.guid}`"
            @click="toggleOne(s.guid)"
          >
            <input
              type="checkbox"
              :checked="selected.has(s.guid)"
              :aria-label="`Select ${s.title}`"
              @click.stop
              @change="toggleOne(s.guid)"
            />
            <div class="font-medium truncate">{{ s.title }}</div>
            <div class="text-text-muted truncate">{{ s.songbook }}</div>
            <div class="text-right text-text-faint">{{ s.verse_count }}</div>
          </div>

          <div
            v-if="filteredSongs.length === 0"
            class="p-10 text-center text-[13px] text-text-faint"
          >
            No songs match.
          </div>
        </div>
      </section>
    </div>

    <Toast v-if="toast" :message="toast.message" :kind="toast.kind" @close="toast = null" />
  </AppShell>
</template>
