<script setup lang="ts">
// Admin-only CRUD for songbooks. Matches the prototype's admin-panel density
// (table-ish rows, inline edit). A songbook that still has songs or is the
// default cannot be deleted — the backend enforces this (409) and we surface
// the returned message.
import { onMounted, reactive, ref } from 'vue'
import AppShell from '@/components/AppShell.vue'
import Icon from '@/components/Icon.vue'
import Toast from '@/components/Toast.vue'
import { useSongbooksStore } from '@/stores/songbooks'
import { extractErrorMessage } from '@/api/client'
import type { Songbook } from '@/types'

const songbooks = useSongbooksStore()

const newForm = reactive({ name: '', description: '' })
const creating = ref(false)
const editingId = ref<string | null>(null)
const editForm = reactive({ name: '', description: '' })
const toast = ref<{ message: string; kind: 'error' | 'success' } | null>(null)

onMounted(() => songbooks.fetchList())

async function onCreate() {
  if (!newForm.name.trim()) return
  creating.value = true
  try {
    await songbooks.create({
      name: newForm.name.trim(),
      description: newForm.description.trim() || null,
    })
    newForm.name = ''
    newForm.description = ''
    toast.value = { message: 'Songbook created.', kind: 'success' }
  } catch (err) {
    toast.value = { message: extractErrorMessage(err, 'Could not create songbook.'), kind: 'error' }
  } finally {
    creating.value = false
  }
}

function startEdit(sb: Songbook) {
  editingId.value = sb.id
  editForm.name = sb.name
  editForm.description = sb.description ?? ''
}

function cancelEdit() {
  editingId.value = null
}

async function saveEdit(sb: Songbook) {
  try {
    await songbooks.update(sb.id, {
      name: editForm.name.trim(),
      description: editForm.description.trim() || null,
    })
    editingId.value = null
    toast.value = { message: 'Saved.', kind: 'success' }
  } catch (err) {
    toast.value = { message: extractErrorMessage(err, 'Could not save.'), kind: 'error' }
  }
}

async function onDelete(sb: Songbook) {
  if (sb.is_default) return
  if (!confirm(`Delete songbook "${sb.name}"?`)) return
  try {
    await songbooks.remove(sb.id)
    toast.value = { message: 'Songbook deleted.', kind: 'success' }
  } catch (err) {
    toast.value = { message: extractErrorMessage(err, 'Could not delete.'), kind: 'error' }
  }
}
</script>

<template>
  <AppShell>
    <div class="px-8 py-6 border-b border-border flex items-center gap-3">
      <div class="font-display font-semibold text-[24px]">Songbooks</div>
      <div class="text-[12px] text-text-faint">Admin · collection management</div>
    </div>

    <div class="px-8 py-6 overflow-auto flex-1">
      <!-- Create form -->
      <form
        class="card p-4 flex items-end gap-3 mb-6"
        data-testid="songbook-create-form"
        novalidate
        @submit.prevent="onCreate"
      >
        <div class="flex-1">
          <label for="sb-name" class="field-label">New songbook</label>
          <input
            id="sb-name"
            v-model="newForm.name"
            class="input"
            placeholder="e.g. Christmas 2026"
            required
            :disabled="creating"
          />
        </div>
        <div class="flex-[2]">
          <label for="sb-desc" class="field-label">Description (optional)</label>
          <input
            id="sb-desc"
            v-model="newForm.description"
            class="input"
            :disabled="creating"
          />
        </div>
        <button class="btn btn-primary" :disabled="creating || !newForm.name.trim()">
          <Icon name="plus" /> {{ creating ? 'Creating…' : 'Create' }}
        </button>
      </form>

      <!-- Existing list -->
      <div class="card" data-testid="songbook-list">
        <div
          v-if="songbooks.loading && songbooks.list.length === 0"
          class="p-5 text-[13px] text-text-faint"
        >
          Loading…
        </div>
        <div
          v-for="(sb, i) in songbooks.list"
          :key="sb.id"
          class="px-4 py-3 flex items-center gap-3"
          :class="i !== songbooks.list.length - 1 ? 'border-b border-border' : ''"
        >
          <template v-if="editingId === sb.id">
            <input v-model="editForm.name" class="input flex-1" />
            <input v-model="editForm.description" class="input flex-[2]" placeholder="Description" />
            <button class="btn btn-primary" @click="saveEdit(sb)">
              <Icon name="check" /> Save
            </button>
            <button class="btn" @click="cancelEdit"><Icon name="x" /></button>
          </template>
          <template v-else>
            <div class="flex-1 min-w-0">
              <div class="text-[14px] font-semibold flex items-center gap-2">
                {{ sb.name }}
                <span v-if="sb.is_default" class="chip chip-accent">default</span>
              </div>
              <div v-if="sb.description" class="text-[12px] text-text-faint mt-[2px]">
                {{ sb.description }}
              </div>
            </div>
            <div class="mono text-[11px] text-text-faint">{{ sb.songs_count }} songs</div>
            <button class="btn" @click="startEdit(sb)">Edit</button>
            <button
              class="btn btn-danger"
              :disabled="sb.is_default"
              :title="sb.is_default ? 'The default songbook cannot be deleted' : undefined"
              @click="onDelete(sb)"
            >
              <Icon name="trash" />
            </button>
          </template>
        </div>
      </div>
    </div>
    <Toast v-if="toast" :message="toast.message" :kind="toast.kind" @close="toast = null" />
  </AppShell>
</template>
