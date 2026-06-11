<script setup lang="ts">
// Admin-only CRUD for songbooks. Matches the prototype's admin-panel density
// (table-ish rows, inline edit). A songbook that still has songs or is the
// default cannot be deleted — the backend enforces this (409) and we surface
// the returned message.
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AppShell from '@/components/AppShell.vue'
import AdminTabs from '@/components/admin/AdminTabs.vue'
import Icon from '@/components/Icon.vue'
import Toast from '@/components/Toast.vue'
import { useSongbooksStore } from '@/stores/songbooks'
import { extractErrorMessage } from '@/api/client'
import type { Songbook } from '@/types'

const songbooks = useSongbooksStore()
const { t } = useI18n()

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
    toast.value = { message: t('songbookAdmin.toast.created'), kind: 'success' }
  } catch (err) {
    toast.value = { message: extractErrorMessage(err, t('songbookAdmin.errors.create')), kind: 'error' }
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
    toast.value = { message: t('common.saved'), kind: 'success' }
  } catch (err) {
    toast.value = { message: extractErrorMessage(err, t('songbookAdmin.errors.save')), kind: 'error' }
  }
}

async function onDelete(sb: Songbook) {
  if (sb.is_default) return
  if (!confirm(t('songbookAdmin.confirmDelete', { name: sb.name }))) return
  try {
    await songbooks.remove(sb.id)
    toast.value = { message: t('songbookAdmin.toast.deleted'), kind: 'success' }
  } catch (err) {
    toast.value = { message: extractErrorMessage(err, t('songbookAdmin.errors.delete')), kind: 'error' }
  }
}
</script>

<template>
  <AppShell>
    <AdminTabs active="songbooks" :subtitle="t('songbookAdmin.subtitle')" />

    <div class="px-8 py-6 overflow-auto flex-1">
      <!-- Create form -->
      <form
        class="card p-4 flex items-end gap-3 mb-6"
        data-testid="songbook-create-form"
        novalidate
        @submit.prevent="onCreate"
      >
        <div class="flex-1">
          <label for="sb-name" class="field-label">{{ t('songbookAdmin.newName') }}</label>
          <input
            id="sb-name"
            v-model="newForm.name"
            class="input"
            :placeholder="t('songbookAdmin.namePlaceholder')"
            required
            :disabled="creating"
          />
        </div>
        <div class="flex-[2]">
          <label for="sb-desc" class="field-label">{{ t('songbookAdmin.descriptionLabel') }}</label>
          <input
            id="sb-desc"
            v-model="newForm.description"
            class="input"
            :disabled="creating"
          />
        </div>
        <button class="btn btn-primary" :disabled="creating || !newForm.name.trim()">
          <Icon name="plus" /> {{ creating ? t('common.creating') : t('common.create') }}
        </button>
      </form>

      <!-- Existing list -->
      <div class="card" data-testid="songbook-list">
        <div
          v-if="songbooks.loading && songbooks.list.length === 0"
          class="p-5 text-[13px] text-text-faint"
        >
          {{ t('common.loading') }}
        </div>
        <div
          v-for="(sb, i) in songbooks.list"
          :key="sb.id"
          class="px-4 py-3 flex items-center gap-3"
          :class="i !== songbooks.list.length - 1 ? 'border-b border-border' : ''"
        >
          <template v-if="editingId === sb.id">
            <input v-model="editForm.name" class="input flex-1" />
            <input v-model="editForm.description" class="input flex-[2]" :placeholder="t('songbookAdmin.descriptionPlaceholder')" />
            <button class="btn btn-primary" @click="saveEdit(sb)">
              <Icon name="check" /> {{ t('common.save') }}
            </button>
            <button class="btn" @click="cancelEdit"><Icon name="x" /></button>
          </template>
          <template v-else>
            <div class="flex-1 min-w-0">
              <div class="text-[14px] font-semibold flex items-center gap-2">
                {{ sb.name }}
                <span v-if="sb.is_default" class="chip chip-accent">{{ t('songbookAdmin.default') }}</span>
              </div>
              <div v-if="sb.description" class="text-[12px] text-text-faint mt-[2px]">
                {{ sb.description }}
              </div>
            </div>
            <div class="mono text-[11px] text-text-faint">{{ t('songbookAdmin.songsCount', { count: sb.songs_count }, sb.songs_count) }}</div>
            <button class="btn" @click="startEdit(sb)">{{ t('common.edit') }}</button>
            <button
              class="btn btn-danger"
              :disabled="sb.is_default"
              :title="sb.is_default ? t('songbookAdmin.defaultCannotDelete') : undefined"
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
