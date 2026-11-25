# KeruGás,App iPhone

App nativo iOS construído com **Capacitor 8** sobre o mesmo frontend React do
site e do app Android. Cada melhoria feita no site chega ao iPhone no próximo build.

- **Bundle ID na App Store:** `ao.kerugas.app` (o mesmo do Android; **não muda nunca**
  depois da primeira publicação)
- **iOS suportado:** 15 ou superior (iPhone e iPad; o iPhone fica sempre na vertical)
- **API usada pelo app:** `https://www.kerugas.ao/api/v1` (ver `.env.ios`)
- **Sem Mac:** o build é feito num Mac alugado na nuvem (**Codemagic**),ver passo 4.

---

## 0. O que já está pronto e o que só você pode fazer

**Já está no projeto (não precisa de fazer nada):**

| O quê | Onde |
|---|---|
| Projeto Xcode gerado | `apps/web/ios/` |
| Frases das permissões (localização, câmara, fotos), em português | `ios/App/App/Info.plist` |
| Ícone e ecrã de abertura (claro e escuro) | `ios/App/App/Assets.xcassets` |
| Push (APNs) e Universal Links | `ios/App/App/App.entitlements` + `AppDelegate.swift` |
| Configuração do Codemagic (build → TestFlight) | `codemagic.yaml` (raiz do repositório) |
| Servidor a falar com a Apple (push direto, sem Firebase) | `apps/api/app/Services/Push/ApnsPushSender.php` |
| Atualização obrigatória por plataforma, CORS do iPhone, ficheiro dos Universal Links | API + `infrastructure/nginx/` |

**Só você pode fazer (exige a sua identidade/pagamento):**

1. Criar a conta **Apple Developer** (paga),passo 1.
2. Criar o Bundle ID, a chave de push e o app no App Store Connect,passos 2 e 3.
3. Ligar o Codemagic à Apple,passo 4.
4. Instalar no iPhone de alguém (TestFlight) e **testar de verdade**,passo 5.
5. Preencher a ficha da App Store e enviar para revisão,passo 7.

> **Honestidade sobre o que não foi possível testar:** este projeto foi preparado sem Mac,
> por isso o **build no Xcode, a assinatura e o TestFlight nunca foram executados**.
> O código React, a API e os testes automáticos foram verificados (345 testes do site,
> testes novos do servidor); a parte nativa só fica provada no primeiro build do
> Codemagic. Se esse primeiro build falhar, é normal,veja o passo 9 e envie-me o log.

---

## 1. Conta Apple Developer

1. Crie (ou use) um **Apple ID** em https://appleid.apple.com com **autenticação de dois fatores** ligada.
2. Inscreva-se em https://developer.apple.com/programs/enroll/,**US$ 99 por ano**.
   - **Individual** (em seu nome) ou **Organização** (precisa de número D-U-N-S da empresa, demora mais).
     Para começar, *Individual* é o mais rápido; o nome do vendedor na App Store será o seu nome.
   - Confirme em https://developer.apple.com/support/enrollment/ se a inscrição está
     disponível no seu país e que cartão aceitam (cartão internacional).
   - A aprovação demora normalmente de algumas horas a 2 dias.
3. Quando aprovada, anote o **Team ID** (10 letras/números): developer.apple.com › Account › Membership details.
   Vai precisar dele em 3 sítios (passos 2, 6 e Universal Links).

## 2. Portal do programador (developer.apple.com › Account)

### 2.1 Bundle ID
*Certificates, Identifiers & Profiles › Identifiers › **+** › App IDs › App*

- Description: `KeruGás`
- Bundle ID: **Explicit** → `ao.kerugas.app`
- Capabilities,marque **estas duas** (sem elas o build assina mal e o push não funciona):
  - ☑ **Push Notifications**
  - ☑ **Associated Domains**

### 2.2 Chave de push (APNs),o `.p8`
*Keys › **+** › nome `KeruGás Push` › ☑ Apple Push Notifications service (APNs) › Continue › Register › **Download***

- O ficheiro `AuthKey_XXXXXXXXXX.p8` **só pode ser descarregado UMA vez**. Guarde-o num cofre de passwords/pen.
- Anote o **Key ID** (as 10 letras/números a seguir a `AuthKey_`).
- Nunca o ponha no git (já está no `.gitignore`).

## 3. App Store Connect (appstoreconnect.apple.com)

### 3.1 Criar o app
*My Apps › **+** › New App*

- Platform: iOS · Name: `KeruGás` (se estiver ocupado: `KeruGás,Gás em Angola`) · Primary language: Portuguese (Portugal)
- Bundle ID: `ao.kerugas.app` · SKU: `kerugas-ios` · User access: Full Access
- Depois de criado: *App Information* → copie o **Apple ID** (número, ex. `6741234567`).
  Vai para `APP_STORE_APPLE_ID` no `codemagic.yaml`.

### 3.2 Chave de API para o Codemagic
*Users and Access › Integrations › App Store Connect API › **+*** › nome `Codemagic` › acesso **App Manager**

- Anote o **Issuer ID** (no topo da página) e o **Key ID** da chave.
- Descarregue o `.p8` desta chave (outra vez só uma vez; **não é o mesmo** da chave de push).

## 4. Codemagic (codemagic.io),o "Mac na nuvem"

**Custos:** o plano gratuito inclui **500 minutos de Mac M2 por mês** (cada build do KeruGás
deve gastar uns 10–20 min → bastam para ~25–40 builds/mês). Passado isso, cerca de
US$ 0,095 por minuto. Confirme os valores atuais em https://codemagic.io/pricing.

1. **Repositório:** o Codemagic lê o código a partir do **GitHub, GitLab ou Bitbucket**.
   Se o projeto ainda não está num deles, crie um repositório **privado** e envie o código
   (inclua a pasta `apps/web/ios/` e o ficheiro `codemagic.yaml`).
2. Entre em codemagic.io com essa conta › **Add application** › escolha o repositório ›
   tipo **Capacitor**/“Other” › o Codemagic deteta o `codemagic.yaml` da raiz.
3. **Ligar à Apple:** *Teams › Personal/Team settings › Integrations › Developer Portal › Connect*
   - Nome da integração: **`Codemagic KeruGas`** (tem de ser igual ao do `codemagic.yaml`)
   - Issuer ID, Key ID e o `.p8` do passo 3.2.
4. **Assinatura automática:** *Teams › Code signing identities › iOS certificates › Generate certificate*
   (tipo *Apple Distribution*, usando a integração acima). O Codemagic cria sozinho o perfil
   para `ao.kerugas.app` quando o build corre.
5. **Variáveis:** *App › Environment variables › grupo `kerugas_ios`* (marque *Secure* nas secretas):

   | Variável | Valor |
   |---|---|
   | `VITE_REVERB_APP_KEY` | o mesmo `REVERB_APP_KEY` do servidor |
   | (opcional) `VITE_API_URL` | só se quiser outro servidor (ex.: de testes) |

6. Edite o `codemagic.yaml` e ponha o **Apple ID** do passo 3.1 em `APP_STORE_APPLE_ID`.
7. **Primeiro build:** *Start new build › workflow `KeruGás iPhone → TestFlight` › branch principal*.
   Ou, daí para a frente, crie uma etiqueta: `git tag ios-1.0.0 && git push --tags`.
8. Quando terminar com ✔, o app aparece no **TestFlight** (App Store Connect › TestFlight) ao fim
   de 5–30 minutos (a Apple “processa” a build primeiro).

> O número da build sobe sozinho a cada envio. A **versão** (1.0.0, 1.1.0…) muda em
> `ios/App/App.xcodeproj/project.pbxproj` → `MARKETING_VERSION` (2 sítios).

## 5. Testar no TestFlight (precisa de um iPhone, mesmo que emprestado)

1. App Store Connect › TestFlight › **Internal Testing** › **+** › grupo `Equipa` › adicione o **e-mail do Apple ID**
   de quem vai testar (até 100 pessoas da equipa da conta).
2. No iPhone, instale a app **TestFlight** da App Store, abra o convite no e-mail e instale o KeruGás.
3. Sem iPhone seu: peça emprestado o de um amigo/familiar por 15 minutos,basta o Apple ID dele
   para o convite (pode removê-lo depois). Um simulador na nuvem **não** abre builds do TestFlight.

**Lista de testes (marque tudo):**

- [ ] Abre sem ecrã branco; o ecrã de abertura desaparece
- [ ] Cabeçalho abaixo do *notch*/Dynamic Island; barra de baixo acima da linha do gesto de início
- [ ] Teclado não tapa o botão “Enviar” (pedido de gás, chat, Vizinhos)
- [ ] Não faz zoom sozinho ao tocar nos campos
- [ ] Registo e login; sair da app e voltar mantém a sessão
- [ ] Localização: aparece o aviso em português e o mapa mostra postos perto
- [ ] Foto de perfil: câmara e galeria
- [ ] Push: peça (noutra conta) um pedido ou atribuição → chega com a app fechada; tocar abre o ecrã certo
- [ ] Link `https://www.kerugas.ao/pedido/…` aberto no WhatsApp/SMS abre direto na app (Universal Links, passo 6.3)
- [ ] Exportar/partilhar um ficheiro (folha de partilha do iOS)
- [ ] Ligar e desligar o modo avião: aparece o aviso de sem ligação

## 6. Servidor

### 6.1 Push (APNs)
1. Copie o `AuthKey_XXXXXXXXXX.p8` para o servidor, **fora de `public/`**: `apps/api/storage/app/AuthKey_XXXXXXXXXX.p8`.
2. No `.env` do servidor:
   ```
   APNS_KEY_PATH=/var/www/html/storage/app/AuthKey_XXXXXXXXXX.p8
   APNS_KEY_ID=<Key ID do passo 2.2>
   APNS_TEAM_ID=<Team ID do passo 1>
   APNS_BUNDLE_ID=ao.kerugas.app
   APNS_SANDBOX=false
   ```
   (caminho como o contentor `backend`/`queue` o vê). TestFlight e App Store usam **sempre**
   `APNS_SANDBOX=false`; `true` só para apps ligadas por cabo ao Xcode.
3. `php artisan config:cache` e reinicie o contentor **`queue`** (o push sai pela fila).
4. Sem estas variáveis o iPhone funciona normalmente,só não recebe push (as notificações
   continuam no sino do app). Android continua pelo Firebase, sem alterações.

### 6.2 Origem do app (CORS)
Já está incluída: o iPhone corre em `capacitor://app.kerugas.ao` (`config/cors.php`). Se usa
`REVERB_ALLOWED_ORIGINS` restrito no Reverb, junte também `capacitor://app.kerugas.ao`.

### 6.3 Universal Links (links `kerugas.ao/pedido/...` abrem no app)
1. Em `infrastructure/nginx/apple-app-site-association`, troque `SUBSTITUIR_TEAM_ID` pelo
   Team ID (fica `ABCDE12345.ao.kerugas.app`).
2. Faça deploy (o ficheiro é montado pelo `docker-compose.yml`) e confirme no navegador:
   `https://www.kerugas.ao/.well-known/apple-app-site-association` → mostra o JSON (sem redirecionar).
3. Teste no iPhone: envie a si próprio, numa nota/mensagem, o link `https://www.kerugas.ao/pedido/KG-1` e toque.
   (O iOS guarda este ficheiro em cache; se falhar, apague e reinstale o app.)

### 6.4 No dia da publicação
No `.env` do servidor: `APP_IOS_STORE_URL=https://apps.apple.com/ao/app/kerugas/id<Apple ID>` e
`APP_IOS_PUBLISHED=true`. O site passa a mostrar o botão “Baixar na App Store” aos iPhones sem novo deploy.
Depois de **cada** versão nova aprovada pela Apple, suba `APP_IOS_LATEST_VERSION` (aviso) e,
só se for mesmo necessário, `APP_IOS_MIN_VERSION` (bloqueia as versões antigas).

## 7. Publicar na App Store

Em App Store Connect › o app › **iOS App › 1.0 Prepare for Submission**:

| Campo | O que pôr |
|---|---|
| Screenshots | iPhone **6.9"** (1320×2868) obrigatório; 6.5" opcional. 3 a 10 imagens. |
| Texto promocional / Descrição / Palavras-chave | em português (use o texto da Play Store) |
| URL de suporte | `https://www.kerugas.ao/contact` |
| URL da política de privacidade | `https://kerugas.ao/privacy` |
| Categoria | Estilo de vida **ou** Utilitários (principal) |
| Classificação etária | responda ao questionário (sem conteúdo sensível → 4+) |
| Preço | Gratuito |
| **App Privacy** | respostas em `docs/app-store-privacy.md` (têm de bater certo com `/privacy`) |
| **Notas para a revisão** | ver abaixo,**é o que mais evita rejeições** |
| Build | escolha a build do TestFlight; responda “Não” à criptografia (já declarado) |

**Notas para a revisão (copie e adapte):**

> O KeruGás permite encontrar postos de gás butano em Angola, ver a disponibilidade e pedir
> entrega ao domicílio. Utiliza funcionalidades nativas do iPhone: localização (postos perto),
> câmara/fotos (foto de perfil), notificações push (estado do pedido e alertas de gás),
> armazenamento seguro no Keychain (sessão) e vibração tátil.
> Conta de teste do cliente: telefone `<nº>` · palavra-passe `<…>`.
> Conta de teste do condutor: telefone `<nº>` · palavra-passe `<…>`.
> A conta pode ser eliminada dentro do app: Perfil › Eliminar conta.
> O app destina-se a Angola; os pedidos de teste não geram entregas reais.

**Pontos que a Apple costuma verificar (o KeruGás já cumpre):**

- ✔ **Eliminar conta dentro do app** (diretriz 5.1.1(v)): Perfil › Eliminar conta.
- ✔ Política de privacidade acessível no app e na ficha.
- ✔ Frases de permissão claras (localização, câmara, fotos).
- ⚠ **Funcionalidade mínima (4.2):** a Apple pode rejeitar apps que parecem “só um site”. O KeruGás
  usa push, localização, câmara, Keychain e interface própria (barra inferior, ecrãs de app) —
  **mencione-o nas notas** (acima). Se rejeitarem, responda no *Resolution Center* explicando isto.
- ⚠ **Pagamentos (3.1.1):** o KeruGás não vende conteúdo digital; a entrega de gás é um bem físico
  pago fora da app,permitido. Não adicione compras dentro da app sem falar comigo.
- ⚠ A revisão demora 1–3 dias; se houver rejeição, a Apple diz exatamente porquê.

**Sempre que lançar uma versão nova:** suba `MARKETING_VERSION`, faça uma etiqueta `ios-x.y.z`, espere o
TestFlight, teste, e então *Add for Review*. Só depois da aprovação suba `APP_IOS_LATEST_VERSION`.

## 8. Dia a dia (para quem tem Mac, ou no futuro)

```bash
cd apps/web
npm install
npm run ios:sync     # build do app + copia para o projeto iOS
npm run ios:open     # abre no Xcode (só em Mac)
npm run ios:assets   # regenera ícone e ecrã de abertura (assets/icon-only.png, splash*.png)
```

Estrutura nativa:

```
apps/web/ios/App/
  App.xcodeproj/        projeto Xcode (assinatura = Automatic; Bundle ID ao.kerugas.app)
  App/Info.plist        permissões em português, push em segundo plano, idiomas
  App/App.entitlements  push (aps-environment) e Universal Links (applinks:)
  App/AppDelegate.swift entrega o token de push ao plugin do Capacitor
  App/Assets.xcassets/  ícone 1024 e ecrã de abertura (claro/escuro)
  CapApp-SPM/           plugins (gerido pelo `cap sync`,não editar)
```

`ios/App/App/public/` e `capacitor.config.json` são **gerados** a cada `ios:sync` (estão no `.gitignore`).

## 9. Se alguma coisa falhar

| Sintoma | Causa provável e solução |
|---|---|
| Codemagic: *“No matching profiles / provisioning profile”* | O Bundle ID não tem **Push Notifications** e **Associated Domains** marcados (2.1), ou a integração com a Apple tem outro nome do que o do `codemagic.yaml`. Corrija e volte a correr. |
| Codemagic: erro na etapa “Número da build” | `APP_STORE_APPLE_ID` ainda é `0000000000`. A etapa tolera “sem builds”, mas precisa do número certo. |
| Codemagic: *“Could not resolve package”* | Faltou `npm ci` (a pasta `node_modules` é onde vivem os plugins),veja o log da etapa 1. |
| Build ✔ mas o TestFlight não mostra nada | Aguarde até 30 min; veja o e-mail da Apple (*“ITMS-…”*),diz o que falta (ex.: frase de permissão). |
| E-mail *“ITMS-91053 Missing API declaration”* | É um aviso de “manifesto de privacidade”; os plugins do Capacitor já trazem o deles. Se persistir, envie-me o e-mail. |
| App abre e fica branco | O servidor não responde em `https://www.kerugas.ao/api/v1` ou falta `VITE_REVERB_APP_KEY` (o app não rebenta sem ela, mas o tempo real não liga). Ligue o iPhone ao Mac/Safari › Develop para ver a consola (build com `KERUGAS_IOS_DEBUG=1`). |
| “Sem ligação ao servidor” só no iPhone | CORS: confirme `capacitor://app.kerugas.ao` em `config/cors.php` e `php artisan config:cache`. |
| Push não chega ao iPhone | `APNS_*` em falta ou errados; `.p8` ilegível pelo contentor; contentor `queue` parado; permissão recusada (Definições › Notificações › KeruGás); `APNS_SANDBOX` certo (TestFlight = `false`). Veja `storage/logs` (“[ApnsPushSender]”). |
| Links `kerugas.ao/pedido/…` abrem no Safari | Falta o Team ID no `apple-app-site-association`, o ficheiro redireciona/não responde JSON, ou falta **Associated Domains** no Bundle ID. |
| Campo faz zoom ao tocar | Não deve acontecer (regra em `globals.css`, secção APP iPHONE). Diga-me qual ecrã. |

## Localização dos condutores em segundo plano (Novembro 2026)

O Condutor partilha a localização também com a app fechada (plugin
`@capacitor-community/background-geolocation`).

- `Info.plist` já tem `NSLocationAlwaysAndWhenInUseUsageDescription` e o modo
  `location` em `UIBackgroundModes`; `CapApp-SPM/Package.swift` já inclui o
  plugin. Depois de `npm install`, correr `npx cap sync ios`.
- No Xcode: *Signing & Capabilities → Background Modes* deve mostrar
  "Location updates" marcado.
- **App Review**: na nota para o revisor explicar que só os condutores
  partilham a localização em segundo plano, para o admin escolher o mais
  perto do cliente, e dar uma conta de condutor de teste.
- O iPhone mostra a seta azul na barra de estado enquanto partilha. Se o
  condutor fechar a app à força, o envio pára e o admin vê "sem localização".
