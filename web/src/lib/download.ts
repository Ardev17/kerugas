import { isNativeApp } from '@/native/platform'

/**
 * Utilitário de download de ficheiros vindos de respostas autenticadas
 * da API. Não dá para simplesmente abrir a URL num link/nova aba —
 * isso faria um pedido GET sem o cabeçalho `Authorization`, e a API
 * devolveria 401. Em vez disso, o pedido passa pelo cliente axios
 * (que já injeta o Bearer token), a resposta é lida como blob, e o
 * download é acionado no browser através de um link temporário.
 *
 * App Android (Setembro 2026): dentro do WebView, `<a download>` com um
 * blob não faz nada,o Android não tem "pasta de transferências do
 * browser" para um WebView. Aí o ficheiro é gravado na cache do app e
 * abre-se a folha de partilha nativa, onde o utilizador escolhe guardar
 * em Ficheiros/Transferências, enviar por WhatsApp/email, ou abrir
 * diretamente num leitor de PDF/folha de cálculo.
 */
export async function triggerBlobDownload(blob: Blob, filename: string): Promise<void> {
  if (isNativeApp) {
    await shareBlobNatively(blob, filename)
    return
  }

  const url = window.URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}

async function shareBlobNatively(blob: Blob, filename: string): Promise<void> {
  // Import dinâmico: os plugins nativos só entram no bundle do app
  // quando são realmente usados, e o site web nunca os carrega.
  const [{ Filesystem, Directory }, { Share }] = await Promise.all([
    import('@capacitor/filesystem'),
    import('@capacitor/share'),
  ])

  const written = await Filesystem.writeFile({
    path: `exports/${sanitizeFilename(filename)}`,
    data: await blobToBase64(blob),
    directory: Directory.Cache,
    recursive: true,
  })

  try {
    await Share.share({
      title: filename,
      dialogTitle: 'Guardar ou partilhar exportação',
      files: [written.uri],
    })
  } catch (error) {
    // Fechar a folha de partilha sem escolher nada não é um erro,o
    // ficheiro foi gerado com sucesso, o utilizador só mudou de ideias.
    if (error instanceof Error && /cancel/i.test(error.message)) {
      return
    }
    throw error
  }
}

function blobToBase64(blob: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onerror = () => reject(reader.error ?? new Error('Falha ao ler o ficheiro exportado.'))
    reader.onload = () => {
      const result = String(reader.result)
      // "data:application/pdf;base64,AAAA..." → "AAAA..."
      resolve(result.slice(result.indexOf(',') + 1))
    }
    reader.readAsDataURL(blob)
  })
}

/** O nome vem do cabeçalho do servidor,nunca deixar "../" chegar ao sistema de ficheiros. */
function sanitizeFilename(name: string): string {
  const cleaned = name.replace(/[\\/:*?"<>|]+/g, '_').replace(/^\.+/, '').trim()
  return cleaned || 'kerugas-exportacao'
}

/**
 * Extrai o nome de ficheiro do cabeçalho Content-Disposition que o
 * Laravel já envia (via Excel::download()/Pdf::download()),usa o
 * nome calculado no servidor em vez de o reconstruir no cliente,
 * para nunca divergirem.
 */
export function filenameFromContentDisposition(header: string | undefined, fallback: string): string {
  const match = header?.match(/filename="?([^"]+)"?/)
  return match?.[1] ?? fallback
}
