# KeruGás,App Android

App nativo Android construído com **Capacitor 8** sobre o mesmo frontend
React do site. Cada melhoria feita no site chega ao app no próximo build.

- **Identificador na Play Store:** `ao.kerugas.app` (não muda nunca depois
  da primeira publicação)
- **Android suportado:** 7.0 (API 24) até 16 (API 36)
- **API usada pelo app:** `https://www.kerugas.ao/api/v1` (ver `.env.android`)

---

## 1. O que é preciso no computador

1. **Android Studio** (versão mais recente),https://developer.android.com/studio
   Já inclui o JDK 21 e o SDK do Android. Na primeira abertura, aceite a
   instalação dos componentes que ele propõe.
2. **Node.js 22 ou superior**,o mesmo que já usa para o site.
3. Um telemóvel Android com a **Depuração USB** ativa
   (Definições › Sobre o telefone › tocar 7× em "Número da compilação" ›
   voltar › Opções de programador › Depuração USB).

## 2. Primeiro build (ver o app no telemóvel)

Na pasta `apps/web`:

```bash
npm install
npm run android:sync     # build do app + copia para o projeto Android
npm run android:open     # abre o projeto no Android Studio
```

No Android Studio, espere que a barra de baixo termine "Gradle sync"
(a primeira vez demora alguns minutos,descarrega as dependências),
escolha o seu telemóvel no topo e carregue em ▶ **Run**.

> Depois de mudar qualquer coisa no código React, basta voltar a correr
> `npm run android:sync` e carregar ▶ outra vez.

## 3. Notificações push (Firebase)

Sem isto o app funciona normalmente,só não recebe push (as
notificações continuam a aparecer no sino, dentro do app).

### 3.1 No Firebase (uma vez)
1. https://console.firebase.google.com › **Adicionar projeto** › "KeruGás".
2. No projeto: ícone Android › **Nome do pacote:** `ao.kerugas.app` › Registar.
3. Descarregue o **`google-services.json`** e ponha-o em
   `apps/web/android/app/google-services.json`.
   (Está no `.gitignore`,não vai para o repositório.)
4. ⚙️ Definições do projeto › **Contas de serviço** › **Gerar nova chave
   privada** › descarrega um JSON. **Este ficheiro é secreto**,dá acesso
   a enviar notificações em nome do KeruGás.

### 3.2 No servidor
1. Copie o JSON da conta de serviço para o servidor, **fora** da pasta
   `public/`, por exemplo `apps/api/storage/app/firebase-credentials.json`.
2. No `.env` do servidor:
   ```
   FCM_CREDENTIALS_PATH=/var/www/html/storage/app/firebase-credentials.json
   ```
   (caminho como o contentor `backend`/`queue` o vê.)
3. `php artisan migrate` (cria a tabela `device_tokens`) e
   `php artisan config:cache`.
4. Confirme que o contentor **`queue`** está a correr,as push saem pela
   fila, nunca atrasam a resposta da API.

### 3.3 Testar
Instale o app, inicie sessão, aceite as notificações, e faça (noutra
conta, pelo painel) uma ação que gere uma notificação para si,por
exemplo, atribuir-lhe um pedido como condutor. Deve chegar em segundos,
mesmo com o app fechado.

## 4. Chave de assinatura (antes de publicar)

Toda a atualização do app tem de ser assinada com a **mesma chave**.
Crie-a uma vez e **guarde uma cópia de segurança** (pen, cofre de
passwords),perdê-la obriga a pedir à Google para a repor.

Na pasta `apps/web/android` (o `keytool` vem com o Android Studio, em
`C:\Program Files\Android\Android Studio\jbr\bin\`):

```bash
keytool -genkeypair -v -keystore kerugas-upload.jks -keyalg RSA -keysize 4096 -validity 10000 -alias kerugas-upload
```

Depois copie `keystore.properties.example` para `keystore.properties` e
preencha as passwords que escolheu. Os dois ficheiros estão no
`.gitignore`.

## 5. Gerar o ficheiro para a Play Store

```bash
npm run android:sync
cd android
gradlew bundleRelease        # no Windows: .\gradlew.bat bundleRelease
```

O ficheiro sai em `android/app/build/outputs/bundle/release/app-release.aab`.
(Em alternativa, no Android Studio: **Build › Generate Signed App Bundle**.)

**A cada nova versão**, antes do build, suba em `android/app/build.gradle`:
- `versionCode`,número inteiro, sempre maior (1, 2, 3...)
- `versionName`,o que as pessoas veem ("1.0.1", "1.1.0"...)

## 6. Publicar na Play Store

1. https://play.google.com/console,conta de programador (pagamento
   único de 25 USD).
2. **Criar app** › nome "KeruGás", idioma Português, App, Gratuito.
3. Aceite a **Assinatura de apps do Google Play** (recomendado).
4. Preencha o que a Play Console pede antes de publicar:
   - **Política de privacidade:** `https://www.kerugas.ao/privacy`
   - **Segurança dos dados:** o app recolhe nome, telefone, email
     (opcional) e localização aproximada/exata; transmitido encriptado
     (HTTPS); a pessoa pode pedir a eliminação da conta.
   - **Permissão de localização:** usada para mostrar postos próximos,
     preencher a morada de entrega e registar a posição de postos.
   - Classificação de conteúdo, público-alvo (18+ ou 13+), capturas de
     ecrã do telemóvel (mínimo 2), ícone 512×512
     (`apps/web/assets/icon-only.png` redimensionado) e imagem de
     destaque 1024×500.
5. **Testes internos** primeiro: envie o `.aab`, adicione os emails dos
   testadores, instale pelo link. Quando estiver tudo bem, promova para
   **Produção**.

## 7. Links que abrem diretamente no app (App Links)

Links como `https://www.kerugas.ao/avaliar/...` (SMS de avaliação do
condutor) e `https://www.kerugas.ao/pedido/...` abrem direto no app
quando ele está instalado. Para o Android confiar nisso:

1. Play Console › o seu app › **Integridade da app** › **Assinatura de
   apps** › copie a **impressão digital do certificado SHA-256** da
   *chave de assinatura de apps*.
2. Cole-a em `infrastructure/nginx/assetlinks.json`, no lugar de
   `SUBSTITUIR:...`.
3. Faça deploy (o nginx já serve este ficheiro em
   `https://www.kerugas.ao/.well-known/assetlinks.json`).
4. Verifique em:
   `https://digitalassetlinks.googleapis.com/v1/statements:list?source.web.site=https://www.kerugas.ao&relation=delegate_permission/common.handle_all_urls`

Sem este passo, os links continuam a funcionar,o Android só pergunta
"abrir com o browser ou com o KeruGás?".

## 8. Atualizar os ícones e o splash

As imagens-fonte estão em `apps/web/assets/` (`icon-only.png`,
`icon-foreground.png`, `icon-background.png`, `splash.png`,
`splash-dark.png`). Depois de as trocar:

```bash
npm run android:assets
```

## 9. Problemas comuns

| Sintoma | Causa provável |
|---|---|
| Ecrã branco / "sem ligação" em tudo | O servidor não responde em `https://www.kerugas.ao/api/v1`, ou o `.env` do servidor não tem `FRONTEND_URL`/CORS certo. A origem do app (`https://app.kerugas.ao`) já está autorizada em `config/cors.php`. |
| Push não chegam | `google-services.json` em falta no build; `FCM_CREDENTIALS_PATH` errado; contentor `queue` parado; ou a pessoa recusou as notificações (Definições › Apps › KeruGás › Notificações). |
| Push chegam com atraso | Poupança de bateria agressiva de algumas marcas (Tecno, Infinix, Xiaomi): Definições › Bateria › KeruGás › "Sem restrições". |
| Links abrem no browser | Passo 7 ainda por fazer, ou a impressão digital não é a da *chave de assinatura de apps* (é a da Play Console, não a da sua chave de upload). |
| "Localização recusada" | A pessoa negou a permissão; pode ativá-la em Definições › Apps › KeruGás › Permissões. |

## Como o app está organizado (para quem mexer no código)

- `capacitor.config.ts`,configuração do app (id, origem, splash, barras do sistema).
- `src/native/`,tudo o que é específico do app: arranque, botão voltar,
  links, push, vibração, barra inferior, aviso de rede. O site web nunca
  executa nada disto (`isNativeApp` / classe `native-app` no `<html>`).
- `src/styles/globals.css`, secção **APP ANDROID**,margens seguras e barra inferior.
- `android/`,projeto nativo gerado pelo Capacitor, com as personalizações
  (manifest, ícone de notificação, tema, assinatura).
- Backend do push: `app/Services/Push/`, `app/Observers/AppNotificationObserver.php`,
  `app/Jobs/SendPushForNotificationJob.php`, `DeviceTokenController`.

## Canais de notificação (Setembro 2026)

Além de `orders`, `alerts` e `general`, a app cria os canais Android **reminders** (lembretes de gás), **tips** (dicas) e **promos** (promoções). O utilizador pode silenciar cada um nas definições do Android ou em *Menu → Notificações*. Tocar numa notificação abre o caminho interno indicado (`data.path`), nunca um endereço externo.

## Testar com o PC (túnel ngrok),Outubro 2026

O app só fala com APIs em `https://` (`allowMixedContent: false`). Para testar no
emulador ou num telemóvel contra a API que corre no PC, expõe-se a API local com um
túnel HTTPS do ngrok.

1. **ngrok:** criar conta em ngrok.com, instalar, `ngrok config add-authtoken <TOKEN>`
   e, no painel (Domains), reclamar o **domínio estático grátis**
   (ex.: `kerugas-dev.ngrok-free.app`). Com ele o URL nunca muda e não é preciso
   voltar a gerar o APK.
2. **API (`apps/api/.env`):** `APP_URL=https://kerugas-dev.ngrok-free.app` e
   `TRUSTED_PROXIES=*`; depois `php artisan config:clear` e
   `php artisan serve --host=127.0.0.1 --port=8000`.
3. **Túnel:** `ngrok http --url=kerugas-dev.ngrok-free.app 8000`. Testar no browser:
   `https://kerugas-dev.ngrok-free.app/api/v1/app/config` deve devolver JSON.
4. **App (`apps/web/.env.android.local`, fora do git):**
   `VITE_API_URL=https://kerugas-dev.ngrok-free.app/api/v1` e `VITE_REVERB_APP_KEY=`
   (vazio,sem tempo real nos testes; os ecrãs atualizam-se sozinhos).
5. **Gerar e instalar:** `npm install`, `npm run android:sync`, `npm run android:open`
   → no Android Studio, ▶ Run (emulador ou telemóvel por USB) ou
   *Build › Build APK(s)* → `android/app/build/outputs/apk/debug/app-debug.apk`.
   Para inspecionar no `chrome://inspect`: `KERUGAS_ANDROID_DEBUG=1` antes do sync.
6. **No fim:** apagar `.env.android.local`, tirar `TRUSTED_PROXIES=*` do `.env` e voltar
   a correr `npm run android:sync` antes de qualquer versão a sério.

O site continua a funcionar em `localhost:5173` ao mesmo tempo. O cabeçalho
`ngrok-skip-browser-warning` é enviado sozinho quando a API é um ngrok.

### Partilhar também o site pelo mesmo túnel

`npm run share` gera o site (modo `share`, com `VITE_API_URL=/api/v1`) e serve-o em
`http://127.0.0.1:4173`, reencaminhando `/api` para o Laravel em `127.0.0.1:8000`.
Aponte o ngrok para a porta **4173** (`ngrok http 127.0.0.1:4173`): o mesmo endereço
serve o site (`https://…ngrok-free.app`) e a API (`https://…ngrok-free.app/api/v1`),
que é também o que se mete no `VITE_API_URL` do app. Só endereços ngrok/cloudflare
são aceites pelo Vite (`allowedHosts`); nada disto existe no site em produção.

## Localização dos condutores em segundo plano (Novembro 2026)

O Condutor (KeruGás e de posto) partilha a localização também com a app
fechada, para o Super Admin geral e o Admin Provincial verem quem está mais
perto do cliente. Usa o plugin `@capacitor-community/background-geolocation`
(um *foreground service* com a notificação fixa "KeruGás,condutor").

- Depois de `npm install`, correr `npx cap sync android` (o plugin já está em
  `capacitor.settings.gradle`).
- `capacitor.config.ts` tem `android.useLegacyBridge: true`,obrigatório para
  as posições continuarem a chegar com o ecrã desligado.
- Permissões que o plugin junta sozinho: `FOREGROUND_SERVICE`,
  `FOREGROUND_SERVICE_LOCATION`, `POST_NOTIFICATIONS`. NÃO usa
  `ACCESS_BACKGROUND_LOCATION`.
- **Play Console** (obrigatório a partir do Android 14): em *Conteúdo da app →
  Serviços em primeiro plano*, declarar o tipo **Localização** e explicar:
  "Os condutores partilham a localização durante o serviço para o
  administrador escolher o condutor mais perto do cliente". Gravar um vídeo
  curto do Painel do Condutor (cartão "Partilhar a sua localização" →
  aceitar → notificação fixa) e pôr o link na declaração.
- Atualizar a *Segurança dos dados*: "Localização exata,recolhida,
  não partilhada com terceiros, finalidade: funcionalidade da app".
- Limite honesto: se o condutor fechar a app à força (deslizar para fora da
  lista de apps) ou alguns telemóveis (Xiaomi, Tecno, Itel, Infinix) a
  matarem para poupar bateria, o envio pára e o admin vê "sem localização".
  Nesses telemóveis, pôr a KeruGás em "Sem restrições" na bateria.
