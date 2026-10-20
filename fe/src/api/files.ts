import { API_BASE_URL } from '@/config'

export type FileItem = {
  name: string
  size: number
  createdAt: string
}

export type LongestPeriodResult = {
  pair: Record<string, string | number>[]
  data: Record<string, string | number>[]
}

export type FileContent = {
  name: string
  rows: Record<string, string | number>[]
}

export type UploadLimit = {
  maxBytes: number
}

async function fetchJson<T> (path: string): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    method: 'GET',
    headers: { Accept: 'application/json' },
  })

  if (!response.ok) {
    const body = await response.json().catch(() => null) as { error?: string } | null
    throw new Error(body?.error ?? `HTTP ${response.status}`)
  }

  return response.json() as Promise<T>
}

export function fetchFiles (): Promise<FileItem[]> {
  return fetchJson<FileItem[]>('/files')
}

export function fetchUploadLimit (): Promise<UploadLimit> {
  return fetchJson<UploadLimit>('/files/limit')
}

export function fetchLongestPeriod (name: string): Promise<LongestPeriodResult> {
  const encoded = encodeURIComponent(name)
  return fetchJson<LongestPeriodResult>(`/files/${encoded}/findLongestPeriodByProject`)
}

export function fetchFileContent (name: string): Promise<FileContent> {
  const encoded = encodeURIComponent(name)
  return fetchJson<FileContent>(`/files/${encoded}`)
}

export async function uploadFile (file: File): Promise<FileItem> {
  const body = new FormData()
  body.append('file', file)

  const response = await fetch(`${API_BASE_URL}/files`, {
    method: 'POST',
    headers: { Accept: 'application/json' },
    body,
  })

  if (!response.ok) {
    const payload = await response.json().catch(() => null) as { error?: string } | null
    throw new Error(payload?.error ?? `HTTP ${response.status}`)
  }

  return response.json() as Promise<FileItem>
}
