import type { CapacitorConfig } from '@capacitor/cli'

/**
 * App Android e iPhone KeruGás (Setembro/Outubro 2026),Capacitor empacota
 * o mesmo frontend React do site como app nativa. Ver apps/web/ANDROID.md
 * (Play Store) e apps/web/IOS.md (App Store) para o passo a passo de build,
 * assinatura e publicação.
 */
const config: CapacitorConfig = {
  // Identificador único na Play Store e na App Store (o "Bundle ID" da Apple
  // é este mesmo). NÃO muda depois da primeira publicação,uma app com
  // outro appId é, para a Google e para a Apple, outra app.
  appId: 'ao.kerugas.app',
  appName: 'KeruGás',
  webDir: 'dist',

  server: {
    // Origem do WebView = https://app.kerugas.ao. Não é um site real: o
    // Capacitor serve os ficheiros do próprio telemóvel sob este nome.
    // Autorizado explicitamente no CORS da API (config/cors.php).
    // Mudar isto depois de publicar apaga o login guardado de todos os
    // utilizadores (o armazenamento do WebView é por origem).
    androidScheme: 'https',
    // iPhone: o iOS não deixa o WebView usar https:// para ficheiros locais,
    // por isso a origem é capacitor://app.kerugas.ao (também autorizada no
    // CORS da API,config/cors.php).
    iosScheme: 'capacitor',
    hostname: 'app.kerugas.ao',
  },

  ios: {
    // O conteúdo desenha-se por baixo do notch e da barra de estado; as
    // margens seguras chegam ao CSS por `env(safe-area-inset-*)` (ver
    // globals.css, secção APP). Combina com `viewport-fit=cover`.
    contentInset: 'never',
    // Sem pré-visualização ao carregar longamente num link dentro do app.
    allowsLinkPreview: false,
    // Fundo atrás do WebView (visível ao puxar para além do fim da página):
    // o mesmo branco do ecrã de abertura.
    backgroundColor: '#FFFFFF',
    // Inspetor do Safari DESLIGADO por omissão (só em builds de teste).
    webContentsDebuggingEnabled: process.env.KERUGAS_IOS_DEBUG === '1',
  },

  android: {
    // Nunca aceitar conteúdo http:// dentro do app.
    allowMixedContent: false,
    // Evita que um teclado de terceiros "aprenda" passwords/telefones.
    // (Os campos de password já são tratados pelo Android à parte.)
    captureInput: true,
    // Inspetor do Chrome (chrome://inspect) DESLIGADO por omissão. Para os
    // testes com túnel (ver ANDROID.md, "Testar com o PC"), gerar o app com
    // KERUGAS_ANDROID_DEBUG=1,nunca numa versão para a Play Store.
    webContentsDebuggingEnabled: process.env.KERUGAS_ANDROID_DEBUG === '1',
    // Localização do condutor em segundo plano (Novembro 2026): exigido pelo
    // plugin @capacitor-community/background-geolocation, senão as posições
    // deixam de chegar ao fim de 5 minutos com o ecrã desligado.
    useLegacyBridge: true,
  },

  plugins: {
    SplashScreen: {
      // Esconde-se manualmente quando o React já pintou o primeiro ecrã
      // (ver src/native/bootstrap.ts),nunca um flash branco entre o
      // splash e a app. O tempo máximo é só uma rede de segurança.
      launchAutoHide: false,
      launchShowDuration: 3000,
      // Azul-marinho da marca (#11264B): o símbolo do splash é branco+laranja,
      // com bom contraste. Mesmo fundo do ícone, por isso a transição é contínua.
      backgroundColor: '#11264B',
      androidScaleType: 'CENTER_CROP',
      showSpinner: false,
      splashFullScreen: false,
      splashImmersive: false,
    },
    // Android 15/16 obriga ao modo "edge-to-edge": a app desenha por
    // baixo da barra de estado e da barra de navegação, e a cor de fundo
    // da barra de estado deixou de ser configurável (o plugin legado
    // @capacitor/status-bar já não funciona para isso no Android 16).
    // O SystemBars do próprio Capacitor 8 é o caminho suportado: com
    // `viewport-fit=cover` no index.html, injeta as margens seguras em
    // `--safe-area-inset-*`, e o nosso CSS usa-as para o cabeçalho se
    // estender até ao topo do ecrã e a barra inferior ficar acima dos
    // gestos do sistema. Também encolhe a app quando o teclado abre, por
    // isso o botão "Enviar" do chat nunca fica escondido.
    SystemBars: {
      insetsHandling: 'css',
      initialViewportFitValueHint: 'cover',
      // Ícones escuros (fundo claro do cabeçalho). Troca com o tema
      // escuro em src/native/systemBars.ts.
      style: 'LIGHT',
    },
    PushNotifications: {
      presentationOptions: ['badge', 'sound', 'alert'],
    },
    // Teclado (Novembro 2026): modo 'body',ao abrir o teclado encolhe
    // apenas a área de conteúdo, não a WebView toda. Assim a tela já não
    // "salta" para cima ao escrever num campo, e o botão "Enviar" do chat
    // continua à vista acima do teclado. (Antes era 'native', que
    // redimensionava a WebView inteira e empurrava tudo.)
    Keyboard: {
      resize: 'body',
    },
  },
}

export default config
