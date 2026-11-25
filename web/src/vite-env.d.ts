/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_URL: string
  readonly VITE_MAP_PROVIDER: string
  readonly VITE_GOOGLE_MAPS_API_KEY: string
  readonly VITE_REVERB_APP_KEY?: string
  readonly VITE_REVERB_HOST?: string
  readonly VITE_REVERB_PORT?: string
  readonly VITE_REVERB_SCHEME?: 'http' | 'https'
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
