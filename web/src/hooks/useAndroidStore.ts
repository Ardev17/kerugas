import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'

export interface AndroidStoreInfo {
  /** O app já está na Play Store? Enquanto não, o botão não tem link. */
  published: boolean
  storeUrl: string | null
}

/**
 * Estado da publicação do app Android, vindo de /app/config (público).
 * Quem decide é o servidor (`APP_ANDROID_PUBLISHED`): no dia em que a ficha
 * da Play Store ficar pública basta ligar essa variável,o site não precisa
 * de ser reconstruído. Falha de rede = "ainda não publicado" (nunca mostra
 * um link morto).
 */
export function useAndroidStore(): AndroidStoreInfo {
  const query = useQuery({
    queryKey: ['app-config', 'android'],
    queryFn: async () => {
      const { data } = await api.get<{ data: { android: { published?: boolean; store_url?: string } } }>('/app/config')
      return data.data.android
    },
    staleTime: 10 * 60 * 1000,
    retry: false,
  })
  const url = query.data?.store_url
  const published = query.data?.published === true && typeof url === 'string' && url.startsWith('https://')
  return { published, storeUrl: published ? (url as string) : null }
}
