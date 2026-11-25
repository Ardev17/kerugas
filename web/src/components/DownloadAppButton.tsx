import { useState } from 'react'
import { pickStore, useAppStores, type Store, type StoreInfo } from '@/hooks/useAppStores'
import { isNativeApp } from '@/native/platform'

const ua = typeof navigator !== 'undefined' ? navigator.userAgent : ''
// iPadOS diz ser "Macintosh"; distingue-se pelo ecrã tátil.
const isIosDevice =
  /iPad|iPhone|iPod/.test(ua) || (typeof navigator !== 'undefined' && navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
const isAndroidDevice = /Android/i.test(ua)

/**
 * Imagens das lojas (Outubro 2026), fornecidas pelo cliente,em
 * public/branding/badges/:
 *  - google-play-icon.png / app-store-icon.png,ícones usados no
 *    "Brevemente", no "Disponível na App Store" e no botão do cabeçalho;
 *  - google-play.png,selo "DISPONÍVEL NO Google Play" (loja já ligada).
 */
const BADGES = '/branding/badges'
const STORE: Record<Store, { name: string; prep: string; icon: string; badge?: string }> = {
  android: { name: 'Google Play', prep: 'no', icon: `${BADGES}/google-play-icon.webp`, badge: `${BADGES}/google-play.webp` },
  ios: { name: 'App Store', prep: 'na', icon: `${BADGES}/app-store-icon.webp` },
}

function StoreIcon({ store, size }: { store: Store; size: number }) {
  return <img src={STORE[store].icon} alt="" aria-hidden="true" width={size} height={size} className="shrink-0 object-contain" style={{ width: size, height: size }} />
}

interface Props {
  /**
   * `pill`,cabeçalho do site (ícone + "Baixar app"; só o ícone em ecrãs médios).
   * `row`,linha inteira no menu do telemóvel.
   */
  variant?: 'pill' | 'row'
  className?: string
  onNavigate?: () => void
}

/**
 * Botão "Baixar app" do cabeçalho e do menu (Outubro 2026). Leva à loja do
 * telemóvel de quem está a ver. Não aparece dentro da própria app, nem
 * enquanto a loja certa ainda não tiver a app publicada (o rodapé mostra os
 * selos "Brevemente",ver StoreBadges).
 */
export function DownloadAppButton({ variant = 'pill', className = '', onNavigate }: Props) {
  const stores = useAppStores()
  if (isNativeApp) return null
  const store = pickStore(stores, { ios: isIosDevice, android: isAndroidDevice })
  if (!store) return null

  const info = stores[store]
  // Versão de teste (Outubro 2026): no Android, antes da Play Store, o botão
  // descarrega o APK diretamente.
  const isTestApk = store === 'android' && !info.published && !!info.apkUrl
  const href = isTestApk ? (info.apkUrl as string) : (info.storeUrl as string)
  const link = { href, target: '_blank', rel: 'noopener noreferrer', onClick: onNavigate } as const

  if (isTestApk) {
    const label = `Baixar versão de teste (APK)${info.apkVersion ? `,${info.apkVersion}` : ''}`
    return variant === 'row' ? (
      <a {...link} className={`flex items-center gap-2 py-2 text-sm font-semibold text-primary ${className}`}>
        <StoreIcon store="android" size={18} /> {label}
      </a>
    ) : (
      <a
        {...link}
        aria-label={label}
        title={label}
        className={`inline-flex items-center gap-1.5 rounded-lg border border-primary/30 px-2.5 py-2 text-sm font-semibold text-primary transition-colors hover:bg-primary/10 ${className}`}
      >
        <StoreIcon store="android" size={18} />
        <span className="hidden xl:inline">Baixar app (teste)</span>
      </a>
    )
  }

  if (variant === 'row') {
    return (
      <a {...link} className={`flex items-center gap-2 py-2 text-sm font-semibold text-primary ${className}`}>
        <StoreIcon store={store} size={18} /> Baixar app
      </a>
    )
  }

  return (
    <a
      {...link}
      aria-label={`Baixar o app KeruGás ${STORE[store].prep} ${STORE[store].name}`}
      title="Baixar app"
      className={`inline-flex items-center gap-1.5 rounded-lg border border-primary/30 px-2.5 py-2 text-sm font-semibold text-primary transition-colors hover:bg-primary/10 ${className}`}
    >
      <StoreIcon store={store} size={18} />
      <span className="hidden xl:inline">Baixar app</span>
    </a>
  )
}

/**
 * Selos do rodapé: Google Play e App Store lado a lado (Outubro 2026). Cada
 * um mostra "Brevemente" (sem link) até a respetiva loja ser ligada no
 * servidor. Não aparecem dentro da própria app.
 */
export function StoreBadges({ className = '' }: { className?: string }) {
  const stores = useAppStores()
  if (isNativeApp) return null
  return (
    <div className={`flex flex-wrap items-center gap-2 ${className}`}>
      <StoreBadge store="android" info={stores.android} />
      <StoreBadge store="ios" info={stores.ios} />
    </div>
  )
}

/** Selo desenhado no mesmo estilo do "DISPONÍVEL NO Google Play" (azul-noite, contorno claro). */
function TextBadge({ store, top, soon }: { store: Store; top: string; soon: boolean }) {
  return (
    <span
      className={`inline-flex h-11 items-center gap-2.5 rounded-[9px] border px-3 text-left ${
        soon ? 'border-white/25 bg-[#071d41]/60 text-white/80' : 'border-[#a6a6a6] bg-[#071d41] text-white'
      }`}
    >
      <StoreIcon store={store} size={26} />
      <span className="leading-none">
        <span className="block text-[9px] font-medium uppercase tracking-wide opacity-80">{top}</span>
        <span className="mt-0.5 block text-[17px] font-semibold tracking-tight">{STORE[store].name}</span>
      </span>
    </span>
  )
}

function StoreBadge({ store, info }: { store: Store; info: StoreInfo }) {
  const s = STORE[store]
  const [badgeFailed, setBadgeFailed] = useState(false)

  // Versão de teste (Outubro 2026): antes da Play Store, o selo do Android
  // descarrega o APK,com um rótulo honesto ("Versão de teste"), nunca
  // "Disponível no Google Play".
  if (store === 'android' && !info.published && info.apkUrl) {
    const label = `Baixar a versão de teste do app KeruGás para Android (APK)${info.apkVersion ? `, ${info.apkVersion}` : ''}`
    return (
      <a
        href={info.apkUrl}
        target="_blank"
        rel="noopener noreferrer"
        aria-label={label}
        title={label}
        className="inline-flex rounded-[9px] transition-transform hover:scale-[1.03] active:scale-95"
      >
        <span className="inline-flex h-11 items-center gap-2.5 rounded-[9px] border border-[#a6a6a6] bg-[#071d41] px-3 text-left text-white">
          <StoreIcon store="android" size={26} />
          <span className="leading-none">
            <span className="block text-[9px] font-medium uppercase tracking-wide opacity-80">Versão de teste{info.apkVersion ? ` · ${info.apkVersion}` : ''}</span>
            <span className="mt-0.5 block text-[17px] font-semibold tracking-tight">Android (APK)</span>
          </span>
        </span>
      </a>
    )
  }

  if (!info.published || !info.storeUrl) {
    return (
      <span role="note" aria-label={`Aplicação brevemente ${s.prep} ${s.name}`}>
        <TextBadge store={store} top={`Brevemente ${s.prep}`} soon />
      </span>
    )
  }

  return (
    <a
      href={info.storeUrl}
      target="_blank"
      rel="noopener noreferrer"
      aria-label={`Baixar o app KeruGás ${s.prep} ${s.name}`}
      className="inline-flex rounded-[9px] transition-transform hover:scale-[1.03] active:scale-95"
    >
      {s.badge && !badgeFailed ? (
        <img src={s.badge} alt={`Disponível ${s.prep} ${s.name}`} className="h-11 w-auto" onError={() => setBadgeFailed(true)} />
      ) : (
        <TextBadge store={store} top={`Disponível ${s.prep}`} soon={false} />
      )}
    </a>
  )
}
