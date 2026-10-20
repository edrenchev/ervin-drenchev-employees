<template>
  <v-dialog
    :model-value="modelValue"
    max-width="960"
    scrollable
    @update:model-value="emit('update:modelValue', $event)"
  >
    <v-card>
      <v-card-title class="d-flex align-center justify-space-between">
        <span>{{ fileName }}</span>
        <v-btn
          icon="mdi-close"
          variant="text"
          @click="emit('update:modelValue', false)"
        />
      </v-card-title>

      <v-divider />

      <v-card-text>
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

        <template v-if="!loading && !error">
          <h2 class="text-h6 mb-2">
            Pair
          </h2>
          <v-data-table
            class="mb-6"
            :headers="pairHeaders"
            :items="pair"
            :items-per-page="-1"
            hide-default-footer
            no-data-text="No pair data"
          />

          <h2 class="text-h6 mb-2">
            Data
          </h2>
          <v-data-table
            class="mb-6"
            :headers="dataHeaders"
            :items="data"
            :items-per-page="-1"
            hide-default-footer
            no-data-text="No analysis data"
          />

          <h2 class="text-h6 mb-2">
            Rows
          </h2>
          <v-data-table
            :headers="rowsHeaders"
            :items="rows"
            :items-per-page="-1"
            hide-default-footer
            no-data-text="No rows"
          />
        </template>
      </v-card-text>
    </v-card>
  </v-dialog>
</template>

<script lang="ts" setup>
  import { computed, ref, watch } from 'vue'
  import {
    fetchFileContent,
    fetchLongestPeriod,
    type FileContent,
    type LongestPeriodResult,
  } from '@/api/files'

  const props = defineProps<{
    modelValue: boolean
    fileName: string | null
  }>()

  const emit = defineEmits<{
    'update:modelValue': [value: boolean]
  }>()

  type Row = Record<string, string | number>

  const loading = ref(false)
  const error = ref<string | null>(null)
  const pair = ref<Row[]>([])
  const data = ref<Row[]>([])
  const rows = ref<Row[]>([])

  function headersFromRows (items: Row[]) {
    const keys = items[0] ? Object.keys(items[0]) : []
    return keys.map((key) => ({ title: key, key }))
  }

  const pairHeaders = computed(() => headersFromRows(pair.value))
  const dataHeaders = computed(() => headersFromRows(data.value))
  const rowsHeaders = computed(() => headersFromRows(rows.value))

  function reset () {
    error.value = null
    pair.value = []
    data.value = []
    rows.value = []
  }

  async function load (name: string) {
    loading.value = true
    reset()
    try {
      const [analysis, content]: [LongestPeriodResult, FileContent] = await Promise.all([
        fetchLongestPeriod(name),
        fetchFileContent(name),
      ])
      pair.value = analysis.pair
      data.value = analysis.data
      rows.value = content.rows
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Failed to load file details'
    } finally {
      loading.value = false
    }
  }

  watch(
    () => [props.modelValue, props.fileName] as const,
    ([open, name]) => {
      if (open && name) {
        void load(name)
      } else if (!open) {
        reset()
      }
    },
  )
</script>
