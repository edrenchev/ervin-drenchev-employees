<template>
  <v-container>
    <h1 class="text-h4 mb-4">
      Files
    </h1>

    <v-progress-linear
      v-if="loading"
      class="mb-4"
      color="primary"
      indeterminate
    />

    <v-alert
      v-if="error"
      class="mb-4"
      type="error"
      variant="tonal"
    >
      {{ error }}
    </v-alert>

    <FileUpload @uploaded="loadFiles" />

    <v-data-table
      :headers="headers"
      :items="files"
      :loading="loading"
      :sort-by="[{ key: 'createdAt', order: 'desc' }]"
      item-value="name"
      no-data-text="No files found"
    >
      <template #item.name="{ item }">
        <a
          href="#"
          @click.prevent="openFile(item.name)"
        >{{ item.name }}</a>
      </template>
      <template #item.size="{ item }">
        {{ formatSize(item.size) }}
      </template>
      <template #item.createdAt="{ item }">
        {{ formatDate(item.createdAt) }}
      </template>
    </v-data-table>

    <FileDetailDialog
      v-model="dialogOpen"
      :file-name="selectedFile"
    />
  </v-container>
</template>

<script lang="ts" setup>
  import { onMounted, ref } from 'vue'
  import { fetchFiles, type FileItem } from '@/api/files'
  import FileDetailDialog from '@/components/FileDetailDialog.vue'
  import FileUpload from '@/components/FileUpload.vue'

  const headers = [
    { title: 'Name', key: 'name' },
    { title: 'Size', key: 'size' },
    { title: 'Created At', key: 'createdAt' },
  ]

  const files = ref<FileItem[]>([])
  const loading = ref(false)
  const error = ref<string | null>(null)
  const dialogOpen = ref(false)
  const selectedFile = ref<string | null>(null)

  function formatSize (bytes: number): string {
    if (bytes < 1024) {
      return `${bytes} B`
    }
    if (bytes < 1024 * 1024) {
      return `${(bytes / 1024).toFixed(1)} KB`
    }
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  }

  function formatDate (iso: string): string {
    const parts = new Intl.DateTimeFormat('bg-BG', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hourCycle: 'h23',
    }).formatToParts(new Date(iso))
    const value = (type: Intl.DateTimeFormatPartTypes) => parts.find(part => part.type === type)?.value ?? ''

    return `${value('day')}.${value('month')}.${value('year')} ${value('hour')}:${value('minute')}:${value('second')}`
  }

  function openFile (name: string) {
    selectedFile.value = name
    dialogOpen.value = true
  }

  async function loadFiles () {
    loading.value = true
    error.value = null
    try {
      files.value = await fetchFiles()
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to load files'
      files.value = []
    } finally {
      loading.value = false
    }
  }

  onMounted(loadFiles)
</script>
