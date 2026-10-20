<template>
  <div class="mb-4">
    <v-file-input
      v-model="file"
      accept=".csv,text/csv"
      label="Upload CSV"
      prepend-icon="mdi-file-upload"
      show-size
      :disabled="uploading"
      :hint="sizeHint"
      :persistent-hint="sizeHint !== ''"
      @update:model-value="onFileChange"
    />

    <v-btn
      class="mt-2"
      color="primary"
      :disabled="uploading"
      :loading="uploading"
      @click="submit"
    >
      Upload
    </v-btn>

    <v-snackbar
      v-model="snackbar"
      color="success"
      location="bottom"
      :timeout="4000"
    >
      {{ success }}
    </v-snackbar>

    <v-alert
      v-if="error"
      class="mt-4"
      type="error"
      variant="tonal"
    >
      {{ error }}
    </v-alert>
  </div>
</template>

<script lang="ts" setup>
  import { computed, nextTick, onMounted, ref } from 'vue'
  import { fetchUploadLimit, uploadFile } from '@/api/files'

  const emit = defineEmits<{
    uploaded: []
  }>()

  const file = ref<File | File[] | null>(null)
  const uploading = ref(false)
  const error = ref<string | null>(null)
  const success = ref('')
  const snackbar = ref(false)
  const maxBytes = ref<number | null>(null)

  function formatSize (bytes: number): string {
    if (bytes < 1024) {
      return `${bytes} B`
    }
    if (bytes < 1024 * 1024) {
      return `${(bytes / 1024).toFixed(1)} KB`
    }
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  }

  const sizeHint = computed(() => {
    const limit = maxBytes.value
    if (limit === null || limit <= 0) {
      return ''
    }
    return `Maximum file size: ${formatSize(limit)}.`
  })

  onMounted(() => {
    void fetchUploadLimit()
      .then((limit) => {
        maxBytes.value = limit.maxBytes
      })
      .catch(() => {
        maxBytes.value = null
      })
  })

  function onFileChange (value: File | File[] | null) {
    error.value = null
    const hasFile = value instanceof File || (Array.isArray(value) && value.length > 0)
    if (hasFile) {
      snackbar.value = false
    }
  }

  async function showSuccess (message: string) {
    success.value = message
    snackbar.value = false
    await nextTick()
    snackbar.value = true
  }

  function chosenFile (): File | null {
    const value = file.value
    if (value instanceof File) {
      return value
    }
    if (Array.isArray(value)) {
      return value[0] ?? null
    }
    return null
  }

  async function submit () {
    error.value = null
    snackbar.value = false
    const current = chosenFile()
    if (!current) {
      error.value = 'Choose a CSV file to upload.'
      return
    }
    if (!current.name.toLowerCase().endsWith('.csv')) {
      error.value = 'Only CSV files are allowed.'
      return
    }

    uploading.value = true
    try {
      const saved = await uploadFile(current)
      file.value = null
      await showSuccess(`Uploaded as ${saved.name}.`)
      emit('uploaded')
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Upload failed.'
    } finally {
      uploading.value = false
    }
  }
</script>
