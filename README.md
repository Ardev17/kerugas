# KERUGAS API (Laravel)

Este diretório contém apenas o **código de aplicação específico do KERUGAS**
(migrations, models, services, controllers, rotas). Não é um projeto Laravel
completo por si só — falta o esqueleto base do framework (bootstrap, vendor,
artisan, etc.), que deve ser gerado localmente.

## Problemas comuns no Windows (PHP nativo, sem Docker)

**Guia completo, passo a passo, com diagnóstico de causa raiz** (o que
fazer se `pest:install` disser "Command not defined", como confirmar
qual `php.ini` está mesmo a ser carregado, como confirmar que o
`.dll` da extensão existe de facto, etc.) em
`docs/troubleshooting-windows.md`. O resumo abaixo cobre os dois casos
mais comuns.

Se vires exatamente estes dois erros ao correr `php artisan pest:install`
(ou qualquer outro comando `artisan`):

```
PHP Warning: PHP Startup: Unable to load dynamic library 'xml' ...
Illuminate\Database\QueryException: could not find driver
(Connection: sqlite, SQL: PRAGMA foreign_keys = ON;)
```

São **dois problemas de configuração do PHP no Windows**, não bugs no
código do KERUGAS. Resolve-se assim:

(As outras duas linhas que aparecem antes — `INFO No publishable
resources for tag [laravel-assets]` e `Found 3 security vulnerability
advisories` — são apenas informativas, não bloqueiam nada. A segunda é
o `composer audit` a avisar de vulnerabilidades conhecidas em pacotes
de desenvolvimento do Pest; podes ver detalhes com `composer audit`,
mas não impede o funcionamento do projeto.)

### 1. Extensão `xml` em falta

```powershell
php --ini
```

Isto mostra o caminho do `php.ini` a ser usado (linha "Loaded
Configuration File"). Abre esse ficheiro num editor de texto e procura
a linha:

```ini
;extension=xml
```

Remove o `;` do início (fica `extension=xml`). Aproveita e confirma/
descomenta também estas, que o Laravel precisa:

```ini
extension=mbstring
extension=fileinfo
extension=openssl
extension=curl
extension=zip
extension=gd
extension=intl
extension=pdo_mysql
```

Confirma também que a linha `extension_dir = "ext"` aponta para a pasta
`ext` correta (normalmente já está certo por omissão).

**Fecha TODAS as janelas do PowerShell e abre uma nova** — o PHP só lê
o `php.ini` uma vez, no arranque do processo.

Confirma com:

```powershell
php -m
```

Deve aparecer `xml`, `pdo_mysql`, `mbstring`, etc. na lista.

### 2. `could not find driver` (sqlite)

O Laravel 11, ao correr `composer create-project`, cria um `.env` novo
com `DB_CONNECTION=sqlite` por omissão (é o novo padrão "zero-config"
do Laravel — não é nada que tenhas feito de errado). **O KERUGAS usa
MySQL**, não sqlite (algumas queries do Admin/Analytics usam sintaxe
específica do MySQL que não funciona em sqlite).

Confirma que o teu `.env` (dentro de `apps/api/`, depois do passo 5 de
montagem acima) tem:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kerugas
DB_USERNAME=root
DB_PASSWORD=
```

E que tens um servidor MySQL real a correr nessa porta, com a base de
dados `kerugas` já criada:

```sql
CREATE DATABASE kerugas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Se não tens MySQL instalado no Windows, as duas opções mais simples são:

- **Laragon** (https://laragon.org) — instala PHP + MySQL + extensões
  já configuradas, sem precisar de mexer em `php.ini` manualmente.
- **Docker** — `docker compose up -d` a partir da raiz do projeto já
  sobe um MySQL configurado (ver `KERUGAS-Tutorial-Deploy.md`, secção 2).
  Evita todo este processo de configurar PHP nativo no Windows.

Depois de corrigir `.env` e ter o MySQL a correr:

```powershell
php artisan key:generate
php artisan migrate --seed
php artisan pest:install
```

## Como montar o backend completo a partir deste código

```bash
# 1. Criar um projeto Laravel novo num diretório temporário
composer create-project laravel/laravel laravel-base "^11.0"

# 2. Copiar o esqueleto do Laravel para cá (mantendo o que já existe aqui)
cp -rn laravel-base/. .
rm -rf laravel-base

# 3. Instalar pacotes adicionais usados pelo domínio KERUGAS
composer require laravel/sanctum

# 4. Publicar configuração do Sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"

# 5. Preparar ambiente
cp ../../.env.example .env
php artisan key:generate

# 6. Migrar e semear
php artisan migrate --seed
```

## Estrutura já incluída

```
app/
├── Enums/            15 enums de domínio (UserRole, Permission, GasOrderStatus, etc.)
├── Events/           StationStatusUpdated, GasAvailabilityChanged, GasOrderCreated
├── Jobs/             RecalculateStationConfidenceJob, ExpireStaleStationStatusesJob
├── Listeners/        DispatchStationAlertsListener, NotifyAdminsOfNewOrderListener
├── Http/
│   ├── Controllers/Api/V1/Auth/  AuthController, PasswordResetController
│   ├── Controllers/Api/V1/Admin/ StationAdminController, UserAdminController,
│   │                    CommentModerationController, ReportAdminController,
│   │                    AdminDashboardController, AdminOrderController
│   ├── Controllers/Api/V1/Operator/ OperatorStationController
│   ├── Controllers/Api/V1/       StationController, TerritoryController, GasOrderController,
│   │                    StationReviewController, StationCommentController,
│   │                    StationReportController, StationConfirmationController,
│   │                    FavoriteController, AlertController, NotificationController
│   ├── Controllers/Api/V1/Webhooks/  SmsWebhookController
│   ├── Middleware/    EnsurePermission, EnsureAccountActive
│   ├── Requests/Admin/  StoreStationRequest, UpdateUserAdminRequest, UpdateReportStatusRequest
│   ├── Requests/      RegisterRequest, LoginRequest, ForgotPasswordRequest,
│   │                  ResetPasswordRequest, StoreGasOrderRequest, NearbyStationsRequest,
│   │                  StoreReviewRequest, StoreCommentRequest, StoreReportRequest,
│   │                  StoreConfirmationRequest, StoreAlertRequest
│   └── Resources/     UserResource, StationResource, AdminStationResource, GasOrderResource,
│                      StationReviewResource, StationCommentResource,
│                      AlertResource, FavoriteResource, NotificationResource
├── Models/            16 models (User, Station, GasOrder, SmsMessage, ...)
├── Notifications/     ResetPasswordNotification (template em português)
├── Policies/          GasOrderPolicy
├── Support/           RolePermissions (mapa papel -> permissões)
└── Services/
    ├── Sms/           SmsProviderInterface, MockSmsProvider, ProductionSmsProvider,
    │                  SmsCommandParser, SmsProcessingService
    ├── StationStatusService.php  (partilhado entre SMS e operador manual)
    ├── PhoneNumberService.php
    ├── TrustScoreService.php
    ├── FreshnessService.php
    └── GasOrderService.php

database/migrations/   11 ficheiros cobrindo as 16 tabelas núcleo + segurança de conta + operador
                        + localização exata do utilizador + tipos de garrafa do posto
database/seeders/      ProvinceSeeder, MunicipalitySeeder, DemoDataSeeder, DatabaseSeeder
database/factories/    ProvinceFactory, MunicipalityFactory, StationFactory,
                        StationStatusUpdateFactory, UserFactory
tests/Feature/         Auth (Register/Login), Sms, GasOrder, Station
tests/Unit/            PhoneNumberService, TrustScoreService, SmsCommandParser
config/kerugas.php     Configurações de domínio (freshness windows, etc.)
routes/api.php         Rotas núcleo + autenticação + comunidade + notificações + operador + admin
routes/console.php     Agendamento (ExpireStaleStationStatusesJob)
routes/channels.php    Autorização de canais de broadcast (Etapa 16)
app/Providers/AppServiceProvider.php  Registo central: bindings, policies, listeners, rate limiters
bootstrap/app.php       Middleware, rotas, channels.php (substitui Kernel.php/RouteServiceProvider antigos)
```

## Registo central (Laravel 11) — `app/Providers/AppServiceProvider.php` e `bootstrap/app.php`

**IMPORTANTE — corrige uma instrução errada de uma versão anterior deste
README**: o Laravel 11 **eliminou por omissão** os ficheiros
`app/Providers/AuthServiceProvider.php`, `RouteServiceProvider.php` e
`app/Console/Kernel.php` que existiam em versões anteriores. Se o teu
`composer create-project laravel/laravel` não os criou, é o comportamento
correto — **não os crie manualmente**. Tudo o que antes vivia espalhado
por esses ficheiros agora está consolidado em dois ficheiros, **já
incluídos neste repositório, prontos a copiar**:

- `app/Providers/AppServiceProvider.php` — binding do `SmsProviderInterface`,
  registo da `GasOrderPolicy` (via `Gate::policy()`, não o antigo array
  `$policies`), os 3 listeners de eventos (Etapa 14), e os 3 rate
  limiters (`orders`, `auth`, `social`).
- `bootstrap/app.php` — aliases de middleware (`permission`,
  `account.active`) e registo de `routes/channels.php` (necessário para
  o broadcasting privado funcionar — Etapa 16).

Depois de copiar `composer create-project laravel/laravel` para dentro
deste diretório (ver secção acima), **sobrescreve** esses dois ficheiros
com os já incluídos aqui — não precisas de escrever nada manualmente.

## Agendamento (`routes/console.php`)

Já incluído neste repositório — apenas confirme que o container `scheduler`
do `docker-compose.yml` está a correr `php artisan schedule:run` a cada
minuto (já configurado no `docker-compose.yml` da raiz). O job
`ExpireStaleStationStatusesJob` corre a cada hora e faz a confiança
decair com o tempo mesmo sem novas atualizações (secção 30).

## Testes (Etapa 17)

Este repositório já inclui testes reais escritos em sintaxe **Pest**
(o padrão do Laravel 11) em `tests/Feature/` e `tests/Unit/`, mais as
`database/factories/` que eles precisam (Province, Municipality, Station,
StationStatusUpdate, User).

Depois de montar o esqueleto do Laravel (ver secção acima):

```bash
composer require pestphp/pest pestphp/pest-plugin-laravel --dev --with-all-dependencies
php artisan pest:install

php artisan test
```

Cobertura incluída:

- `PhoneNumberServiceTest` — normalização de números angolanos (todas as variações colapsam para o mesmo valor canónico)
- `TrustScoreServiceTest` — score de confiança em função de fonte/atualidade
- `SmsCommandParserTest` — todos os códigos 00-12 e casos inválidos
- `SmsProcessingServiceTest` — **as 3 primeiras regras críticas de negócio** (secção 146): só número autorizado atualiza, SMS inválido não altera estado, SMS duplicado não duplica atualização
- `RegisterTest` / `LoginTest` — incluindo o **account lockout** (5 tentativas → bloqueio de 15 min)
- `CreateGasOrderTest` — pedido sem autenticação, referência pública única, validação de coordenadas
- `StationReviewTest` — autenticação obrigatória, unicidade de avaliação por utilizador

## Real-time — Laravel Reverb (Etapa 16)

Os eventos `StationStatusUpdated` e `GasOrderCreated` já implementam
`ShouldBroadcast` desde a Etapa 3/14. Falta apenas ligar o servidor
WebSocket:

```bash
composer require laravel/reverb
php artisan reverb:install
```

Isto publica a configuração `config/broadcasting.php` e adiciona as
variáveis `REVERB_*` ao `.env`. Defina:

```
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=kerugas
REVERB_APP_KEY=<gerado pelo reverb:install>
REVERB_APP_SECRET=<gerado pelo reverb:install>
REVERB_HOST=localhost
REVERB_PORT=8080
```

**`routes/channels.php` já está incluído neste repositório** — define que
o canal `admin.orders` (usado por `GasOrderCreated`) é **privado** e só
autoriza ADMIN/SUPER_ADMIN. O canal `stations` (usado por
`StationStatusUpdated`) é público de propósito, porque disponibilidade e
fila já são informação pública em qualquer outra parte do site.

Correr o servidor (além do `php artisan serve` e do worker de filas):

```bash
php artisan reverb:start
```

No `docker-compose.yml`, adicionar um serviço `reverb` equivalente ao
`queue`/`scheduler`, mas com `command: php artisan reverb:start --host=0.0.0.0`
e a porta 8080 exposta.

## Atualização incremental — localização exata, limite de SMS, garrafas, rota de entrega

- **Rate limit de SMS**: máximo 3 consultas "GAS" por número, por dia
  (`SmsProcessingService::MAX_DAILY_CONSUMER_QUERIES`). Ao exceder, o
  número recebe uma SMS informativa em vez de ser processado.
- **Localização exata opcional no registo**: `users.latitude/longitude`,
  preenchidos só se o utilizador autorizar geolocalização no frontend.
  Nunca obrigatório, nunca exposto publicamente (só via `/users/me` e
  `/admin/users`, ambos autenticados).
- **Tipos de garrafa** (`stations.current_cylinder_types`): string livre,
  nomes separados por vírgula. Só escrito por `OperatorStationController`
  e pelo novo `StationAdminController::updateStatus` — o SMS nunca toca
  neste campo, por isso mantém sempre o valor da última atualização
  manual (comportamento pedido explicitamente).
- **Traçar rota de entrega**: não precisou de endpoint novo — o
  `GasOrderResource` já expunha `latitude`/`longitude`; o frontend
  gera um link do Google Maps sem `origin`, que usa a localização atual
  do dispositivo automaticamente. Disponível para Admin (`AdminOrders`)
  e Operador (`OperatorOrders`, novo — o Operador ganhou a permissão
  `orders.view_all`, só leitura, em `RolePermissions`).

## Exportação de dados (CSV + PDF)

**Só um pacote é preciso agora** (o "Excel" é CSV puro, gerado sem
nenhuma dependência — abre perfeitamente no Excel, incluindo
acentuação portuguesa, mas evita o pacote maatwebsite/excel que exigia
mais extensões PHP a configurar):

```bash
composer require barryvdh/laravel-dompdf
```

Publica a sua configuração automaticamente (não é preciso
`vendor:publish`). Requer as extensões `mbstring`, `gd` — as mesmas
que já tiveste de ativar no `php.ini` para o Pest funcionar (ver
secção "Problemas comuns no Windows" acima).

Se este pacote não estiver instalado, a exportação em PDF falha com
uma mensagem clara a dizer exatamente isso (em vez de um erro técnico
em bruto) — a exportação em CSV funciona sempre, mesmo sem este
pacote.

**Rotas**:
- `GET /operator/station/export?format=csv|pdf&period=day|week|month&date=YYYY-MM-DD`
  — sempre restrito ao posto do operador autenticado, mesmo que
  `station_id` seja enviado (é sempre ignorado nesta rota).
- `GET /admin/stations/export?format=...&period=...&date=...&station_id=&municipality_id=&province_id=`
  — os três filtros de âmbito são opcionais e combináveis; sem
  nenhum, exporta todos os postos no período. Requer a permissão
  `analytics.export` (Admin/Super Admin — o Moderador não a tem).

**Ficheiros**: `StationStatusExportService` (motor único, partilhado),
`Exports/StationStatusExport` (folha Excel), `resources/views/exports/
station-status.blade.php` (template PDF), `OperatorStationController::
export()`, `Admin/StationExportController`.

## `.env` adicional necessário

```
APP_FRONTEND_URL=http://localhost:5173
```

E em `config/app.php`, adicionar a chave `frontend_url` lida de `env('APP_FRONTEND_URL')`
(usada pelo `ResetPasswordNotification` para montar o link correto para o SPA).

## Seeders (território + dados demo)

```bash
php artisan migrate --seed
```

Isto executa, pela ordem correta:

1. `ProvinceSeeder` — as **21 províncias reais** de Angola, em vigor desde
   1 de janeiro de 2025 (Lei 14/24). Fonte verificada via pesquisa web
   (Diário da República, reportagens do Público/CM Jornal, Wikipédia).
2. `MunicipalitySeeder` — ~150 municípios reais e verificados (não os
   326 oficiais — ver comentário no ficheiro sobre a decisão técnica
   tomada e o que precisa de reconciliação antes de produção,
   sobretudo nas 3 províncias novas resultantes de divisões).
3. `DemoDataSeeder` — utilizadores demo (um por papel) e 3 postos
   `[DEMO]` em Lubango e Luanda, para testar o fluxo completo
   (Find Gas, Map, Station Details, Order) sem depender de dados reais.

### Credenciais demo (apenas ambiente local/desenvolvimento)

| Papel | Telefone | Email | Password |
|---|---|---|---|
| Super Admin | +244900000001 | superadmin@demo.kerugas.com | `Demo@2026` |
| Admin | +244900000002 | admin@demo.kerugas.com | `Demo@2026` |
| Moderador | +244900000003 | moderador@demo.kerugas.com | `Demo@2026` |
| Operador de Posto | +244900000004 | operador@demo.kerugas.com | `Demo@2026` |
| Utilizador | +244900000005 | utilizador@demo.kerugas.com | `Demo@2026` |

**Nunca usar estas credenciais ou este seeder em produção.**

## Central de Operações KeruGás (SMS de resposta manual)

### Regra fundamental (LEIA ANTES DE MEXER NESTE MÓDULO)

Existe uma diferença fundamental entre **SMS recebido** e **SMS enviado**
pelo KeruGás. Nesta fase, o KeruGás **não recebe automaticamente** os
SMS que os clientes enviam para o número oficial. O fluxo real é:

```
CLIENTE --SMS--> TELEFONE/CHIP FÍSICO DA CENTRAL --admin lê-->
CENTRAL DE OPERAÇÕES (KeruGás) --consulta postos, gera resposta-->
ADMIN CONFIRMA --Laravel--> Gateway de SMS --> Operadora --> CLIENTE
```

O SMS entra no telefone físico. O atendimento entra manualmente no
KeruGás. A resposta sai automaticamente pelo gateway de SMS. **Nunca**
existe: receção automática de SMS de clientes, webhook de SMS
recebido de clientes, inbox de SMS, "Central de Solicitações"
automática, ou qualquer leitura automática do chip físico.

O único SMS que o KeruGás processa automaticamente (via
`POST /api/v1/webhooks/sms` → `SmsProcessingService`) é o código de 2
dígitos que os **próprios postos** enviam para autoatualizar o seu
estado (ex: `"10"` = tem gás + fila baixa). Isso é o posto a
autoatualizar-se — não é um cliente, e nunca gera resposta automática
a ninguém.

### Módulos

- **Responder Cliente** (`/admin/operations`) — o admin introduz o
  telefone do cliente (normalizado por `PhoneNumberService`), a
  província/município e, opcionalmente, a mensagem que leu no
  telefone físico. O sistema consulta os postos do município, o admin
  escolhe um template, vê a pré-visualização (mensagem final,
  caracteres, segmentos SMS) e confirma o envio.
- **Templates SMS** (`sms_templates`) — geridos dentro do KeruGás.
  Variáveis permitidas (whitelist, `SmsTemplateRenderer`):
  `{{cliente}} {{telefone}} {{provincia}} {{municipio}}
  {{quantidade_postos}} {{postos}} {{data}} {{hora}} {{posto}}
  {{localizacao}} {{disponibilidade}} {{fila}}`.
- **Histórico** (`sms_dispatches`) — todos os SMS enviados pela
  Central, com estado (`PENDING/QUEUED/SENT/DELIVERED/FAILED/UNKNOWN`).
  Nunca confundir "enviado" com "entregue" — são estados distintos.

### Gateway de SMS

Abstraído via `SmsProviderInterface` (`send`, `verifyWebhook`,
`normalizeIncomingMessage`). `MockSmsProvider` é usado em
desenvolvimento/testes (`SMS_PROVIDER=mock`, o padrão). O gateway real
ainda **não foi escolhido** — `ProductionSmsProvider` implementa um
contrato HTTP genérico configurável via `.env`, propositadamente sem
inventar endpoints/payloads de nenhum fornecedor específico:

```
SMS_PROVIDER=production
SMS_API_KEY=
SMS_API_SECRET=
SMS_WEBHOOK_SECRET=
```

Quando o gateway definitivo for escolhido, só é preciso reescrever o
corpo de `ProductionSmsProvider` — nada mais no sistema muda, porque
toda a lógica de negócio (templates, consulta de postos, regras de
município) vive exclusivamente no KeruGás, nunca no gateway.

### Tabelas novas

- `sms_templates` — templates geridos pelo Admin/Super Admin.
- `operations` — registo de um atendimento manual (nunca representa
  receção automática de SMS — ver comentário na migration).
- `sms_dispatches` — histórico de SMS enviados (deliberadamente
  separada de `sms_messages`, que é exclusiva do código de 2 dígitos
  dos postos).

### Acesso

Estritamente `ADMIN` e `SUPER_ADMIN` (permissões `operations.manage`,
`sms.send`, `sms.templates.manage`, `sms.view`). Nenhum outro papel
tem acesso a este módulo.

## Âmbito por província — Admin (Setembro 2026)

Um `ADMIN` só vê e só consegue gerir dados **da sua própria província**
(`users.province_id`). Só o `SUPER_ADMIN` mantém CRUD completo, sem
filtro nenhum, em todas as áreas abaixo. A lógica central (quem está
escopado, e a qual província) vive num único sítio —
`App\Support\Concerns\ScopesToAdminProvince` — usado por todos os
controllers afetados, para nunca divergir entre eles.

Áreas cobertas por este âmbito, pedido explícito do cliente:

| Área | Escopado? | Onde |
|---|---|---|
| Pedidos de gás (`gas_orders`) | Sim (já existia) | `AdminOrderController` |
| Postos (`stations`) | Sim | `StationAdminController`, `StoreStationRequest` |
| Comentários (`station_comments`) | Sim | `CommentModerationController` |
| Denúncias (`station_reports`) | Sim | `ReportAdminController` |
| Analytics (`/admin/analytics`) | Sim (parcial — ver nota) | `AdminAnalyticsController` |
| Exportação (CSV/PDF) | Sim | `StationExportController` |
| Dashboard (KPIs) | Sim, para `stations`/`gas_orders`/`reports` | `AdminDashboardController` |
| Central de Operações | **Não** — inalterado, por pedido explícito | — |
| Utilizadores (`users`) | Sim (revisto — ver nota) | `UserAdminController`, `UpdateUserAdminRequest` |

Padrão aplicado em cada área com CRUD (Postos, Comentários, Denúncias,
Pedidos): duas camadas de proteção, sempre juntas —

1. **Filtro na listagem** (`index`): a query já sai filtrada pela
   província do Admin, para nunca aparecer nada fora do âmbito.
2. **Verificação por registo** (`assertProvinceAccess`, erro `403`):
   obrigatória mesmo com o filtro acima, porque o route model binding
   do Laravel resolve `{model}` diretamente pelo ID, contornando
   qualquer filtro de listagem — sem esta camada, um Admin escoado
   conseguiria agir sobre um registo de outra província só por
   adivinhar/colocar o ID diretamente no pedido.

Onde a criação/edição também está em causa (Postos), há ainda uma
terceira camada, de **validação** (erro `422`, distinto do `403`
acima): `StoreStationRequest::assertProvinceScope()` recusa um Admin
submeter `province_id` diferente do seu, ou um `municipality_id` que
pertença a outra província — 403 é "não pode tocar num registo já
existente"; 422 é "os dados que enviou são inválidos para a sua
conta".

`station_comments` e `station_reports` não têm coluna própria de
província — vem sempre do posto relacionado
(`whereHas('station', fn ($q) => $q->where('province_id', ...))`, e
`$model->station?->province_id` na verificação por registo).

### Nota sobre Analytics

A tabela `analytics_events` não tem nenhuma coluna de localização —
só o evento `station_search` guarda um nome de município em texto
livre no `payload` (nunca um FK). Por isso:

- `searches_by_day` e `top_municipalities_searched` são filtrados
  traduzindo a província do Admin para a lista de nomes de município
  que lhe pertencem, e comparando com `payload->municipality`.
- `orders_by_day` e os dois gráficos de postos usam `province_id`
  diretamente (`gas_orders`/`stations` têm a coluna).
- `page_views_by_day` **fica sempre nacional**, mesmo para um Admin
  escopado — o evento `page_view` não carrega localização nenhuma
  (`{ page: 'home' }`), não há nada a filtrar. Fingir um filtro aqui
  seria pior do que não ter filtro nenhum.
- A resposta inclui um campo `scope` (`{ type: 'province', province }`
  ou `{ type: 'national' }`) para o frontend poder avisar sobre isto,
  em vez do gráfico "desaparecer" dados sem explicação — já usado no
  banner da página `AdminAnalytics.tsx`.

### Nota sobre Exportação

`StationExportController::export()` força sempre `province_id` ao
valor da conta do Admin, substituindo silenciosamente qualquer valor
enviado no pedido. Um Admin sem província atribuída é bloqueado com
`403` **antes** de chamar o serviço de exportação — nunca se apoia
apenas no `!empty($filters['province_id'])` interno do
`StationStatusExportService`, porque `empty(0)` é `true` em PHP: como
"sem província" usa `0` como sentinela, deixar esse valor cair no
`!empty()` faria o filtro ser silenciosamente ignorado, e a
exportação sairia com dados de Angola inteira — o oposto exato do
pedido.

### Nota sobre Utilizadores (revisão — pedido inicial dizia "sem alteração")

O pedido original tinha deixado esta área de fora ("fica assim como
está"), mas foi revisto pouco depois: um `ADMIN` só vê, edita,
bloqueia/desbloqueia e promove utilizadores **da sua própria
província** — mesmo padrão de duas camadas das outras áreas (filtro
na listagem + `assertProvinceAccess`, erro `403`, em `update()`).

Isto já convivia com uma regra mais antiga e independente: um `ADMIN`
nunca pode tocar em contas `ADMIN`/`SUPER_ADMIN` (só o `SUPER_ADMIN`
promove/gere papéis privilegiados) — as duas regras aplicam-se juntas,
sem conflito, porque cobrem perguntas diferentes ("desta província?"
vs "este papel?").

Uma lacuna adicional foi fechada nesta revisão:
`UpdateUserAdminRequest::assertAdminCannotReassignProvince()` impede
um `ADMIN` de mudar a `province_id` de qualquer utilizador (erro `422`)
— sem isso, um Admin poderia contornar o âmbito acima simplesmente
"emprestando" um utilizador para dentro/fora da sua província. Só o
`SUPER_ADMIN` pode reatribuir a província de alguém; um `ADMIN` pode
continuar a reenviar o mesmo valor já existente sem erro (o caso normal
ao editar outro campo qualquer, ex: bloquear/desbloquear).

### Outras decisões

- `UpdateUserAdminRequest` exige `province_id` sempre que o papel
  resultante (já guardado ou a ser definido neste pedido) for `ADMIN`
  — nunca é possível guardar/promover um Admin sem província, nem
  remover a província de um Admin já existente.
- `MODERATOR` mantém-se sem filtro em toda a parte (o pedido do
  cliente falou explicitamente de "administrador" vs "super
  administrador") — revisitar se o mesmo âmbito regional fizer
  sentido também para moderadores.
- Central de Operações fica **exatamente como estava**, sem nenhuma
  restrição nova — pedido explícito do cliente, mantido mesmo após a
  revisão.

Testes: `tests/Feature/Admin/AdminOrderProvinceScopeTest.php`,
`AdminStationProvinceScopeTest.php`, `AdminCommentProvinceScopeTest.php`,
`AdminReportProvinceScopeTest.php`, `AdminAnalyticsProvinceScopeTest.php`,
`AdminExportProvinceScopeTest.php`, `AdminUserProvinceScopeTest.php`.

## Auditoria de segurança e correções (Setembro 2026)

Depois de uma revisão pedida explicitamente pelo cliente, sem filtro,
sobre os tipos de ataque que o sistema pode sofrer. Corrigido nesta
revisão:

1. **`/webhooks/sms` sem rate limit** — a rota não tinha NENHUM
   limite; mesmo um pedido sem assinatura válida ainda gasta CPU/DB a
   validar o HMAC antes de rejeitar, tornando-a um alvo barato de
   flood/DoS. Adicionado `throttle:sms-webhook` (30/min por IP,
   `AppServiceProvider.php`).
2. **Rotas públicas de leitura sem rate limit** — `/provinces`,
   `/provinces/{p}/municipalities`, `/stations/nearby`,
   `/stations/{station}`, `/map/stations`, `/stations/{station}/reviews`
   e `/stations/{station}/comments` não tinham nenhum limite.
   Adicionado `throttle:public-read` (60/min por IP) a todas.
   **`/map/stations` era o alvo mais barato**: devolve todos os postos
   ativos de uma vez, sem paginação.
   IMPORTANTE: propositadamente **não** foi adicionada cache a
   `forMap()` como mitigação alternativa — já houve um bug real (ver o
   comentário "BUG CORRIGIDO (nº 2)" no próprio `StationController.php`)
   em que uma cache sem invalidação fazia atualizações do
   Operador/Admin desaparecerem do mapa até expirar. Rate limit é a
   mitigação certa aqui; cache voltaria a introduzir esse bug.
3. **`MockSmsProvider` aceitava tudo, mesmo em produção** — se
   `SMS_PROVIDER` fosse esquecido/mal configurado num deploy real
   (o default em `config/kerugas.php` é `"mock"`), o webhook de SMS
   ficava completamente aberto a forjar atualizações de estado de
   postos, sem assinatura nenhuma. Corrigido: `MockSmsProvider::verifyWebhook()`
   agora rejeita SEMPRE que `APP_ENV=production`, independentemente do
   valor de `SMS_PROVIDER` nesse momento.
4. **`config/cors.php` e `config/sanctum.php` não existiam** —
   sem eles, a app corria nos defaults do framework: CORS aberto a
   qualquer origem (`*`) e tokens de acesso que nunca expiram. Criados
   os dois, com CORS restrito a `FRONTEND_URL` e expiração de token
   configurável via `SANCTUM_TOKEN_EXPIRATION_MINUTES` (90 dias por
   omissão). **Nota importante**: esta árvore de trabalho não contém
   `composer.json`, `composer.lock`, `artisan`, `public/` nem
   `phpunit.xml` — só a camada de aplicação (`app/`, `database/`,
   `routes/`, `tests/`, `config/kerugas.php`). Se o projeto real já
   tiver as suas próprias versões destes dois ficheiros de config,
   compare antes de substituir. Não foi possível verificar versões de
   dependências (Laravel/PHP/Sanctum) nem correr `composer audit` a
   partir daqui por este motivo — peço que confirme isso no ambiente
   real.
5. **Ações de Admin sem rasto em `AuditLog`** — moderação de
   comentários (`CommentModerationController::updateStatus`) e
   resolução de denúncias (`ReportAdminController::updateStatus`) não
   deixavam nenhum registo; se uma conta de Admin fosse comprometida,
   podia moderar conteúdo ou fechar denúncias sem ninguém saber quem
   fez o quê depois. Adicionado `AuditLog::create` aos dois. Também
   melhorado `GasOrderService::assign()` para registar explicitamente
   a quem um pedido foi atribuído (antes só registava "mudou para
   ASSIGNED", sem dizer para quem).

Testes: `tests/Feature/Security/SmsWebhookSecurityTest.php`,
`AdminAuditLoggingTest.php`.

6. **HTTPS não estava configurado** — o nginx só servia por `http://`,
   sem redirecionamento nem certificado nenhum. Fechado (Setembro 2026):
   `infrastructure/nginx/nginx.conf` agora tem o domínio real
   (`www.kerugas.ao`/`kerugas.ao`) com um bloco `:443 ssl http2`
   completo (HSTS, `TLS 1.2`/`1.3`, os mesmos cabeçalhos de segurança do
   bloco `:80`), redireciona qualquer pedido `http://` do domínio para
   `https://`, e adiciona `fastcgi_param HTTPS on;` para o Laravel gerar
   URLs `https://` corretamente. O certificado é emitido e renovado
   automaticamente pela Let's Encrypt via um novo serviço `certbot` no
   `docker-compose.yml`; a ativação inicial em produção (uma única vez,
   com o domínio já a apontar em DNS) é feita por
   `infrastructure/nginx/init-letsencrypt.sh` — ver o comentário no topo
   desse script e do próprio `nginx.conf` para o passo a passo. O bloco
   por omissão (`default_server`, sem domínio) continua a servir por
   `http://` sem certificado, para acesso direto por IP/localhost em
   ambiente de teste, sem ficar bloqueado à espera de um certificado que
   não existe nesse caso.

Pontos que ficaram fora desta revisão (não pedidos explicitamente,
documentados para decisão futura):
- Interpolação direta de `lat`/`lng` na fórmula de Haversine em
  `StationController::nearby()` — hoje seguro porque
  `NearbyStationsRequest` valida como `numeric` antes, mas é um padrão
  frágil que reabriria uma SQL injection se reutilizado sem essa
  validação.
- `composer.json`/`composer.lock` ausentes desta árvore — impossível
  confirmar versões de dependências ou correr `composer audit` a
  partir daqui.

## Faturação por entrega (Setembro 2026)

Pedido explícito do cliente: saber "quanto se fatura por dia" e "quanto
cada Admin faturou", a partir do preço cobrado ao cliente pela entrega
de gás.

**Modelo de negócio** (decisões confirmadas com o cliente antes de
implementar, via perguntas diretas):
- O preço varia com a distância à Central, mas é **sempre negociado
  por telefone** entre o Admin e o cliente — o sistema **nunca** o
  calcula automaticamente. Não existe nenhuma fórmula de distância no
  código.
- O Admin só regista o preço já acertado, no momento de **confirmar**
  o pedido (`CONTACTING` → `CONFIRMED`). Antes disso o preço não
  existe — é obrigatório (`422` sem ele) e faz parte do próprio pedido
  de confirmação (`POST /admin/orders/{id}/confirm`, campo
  `delivery_price`).
- Depois de confirmado, o preço só pode ser corrigido pelo
  **SUPER_ADMIN** (`PATCH /admin/orders/{id}/price`) — nunca pelo
  Admin comum, mesmo sendo o pedido da sua própria província. Toda
  correção fica em `AuditLog` (valor antigo/novo) e em
  `price_corrected_by`/`price_corrected_at` no próprio pedido, para
  aparecer de imediato na interface sem ter de consultar o log.
- Um Admin comum só vê, na página de Faturação, os valores que **ele
  próprio** registou. O Super Admin vê tudo: o somatório geral e o
  detalhe por Admin (`GET /admin/billing`, permissão `billing.view`).

**Âmbito diferente do resto do módulo Admin**: aqui filtra-se por
**quem registou o preço** (`gas_orders.price_set_by`), não por
província — documentado explicitamente no docblock de
`BillingController`. Na prática já coincide com o âmbito por
província (um Admin só confirma pedidos da sua própria província),
mas filtrar por `price_set_by` deixa a regra explícita e não quebra se
o âmbito por província alguma vez mudar.

**Esquema**: migração `add_delivery_price_to_gas_orders_table` adiciona
a `gas_orders`: `delivery_price` (decimal 12,2, nullable — só existe a
partir da confirmação), `price_set_by`/`price_corrected_by` (FK para
`users`, nullable) e `price_set_at`/`price_corrected_at` (timestamps
nullable).

**Backend**: `GasOrderService::confirm()` agora exige o preço;
`AdminOrderController::confirm()` valida-o (`required|numeric|min:0`
— `min:0` e não `min:0.01` porque uma entrega gratuita/cortesia é um
caso de negócio válido, não um erro); `AdminOrderController::updatePrice()`
restringe a correção ao Super Admin (verificado dentro do controller,
mesmo padrão de `StationAdminController::toggleAnalytics`, porque a
permissão de rota `orders.manage` também é dada ao Admin comum);
`BillingController::index()` calcula o resumo de hoje/total, a série
diária dos últimos 14 dias e, só para Super Admin, o detalhe por
Admin. Nova permissão `Permission::BILLING_VIEW`, dada a
ADMIN/SUPER_ADMIN (nunca a MODERATOR/STATION_OPERATOR).

**Frontend**: confirmar um pedido em `/admin/orders` abre um pequeno
modal a pedir o preço antes de disparar a ação (já não é um botão de
um clique só, como as outras transições de estado); o modal de
detalhes do pedido mostra o preço registado e, só para Super Admin,
permite corrigi-lo. Nova página `/admin/billing` ("Faturação" na barra
lateral, mesmas contas que veem "Pedidos") com KPIs, gráfico de barras
dos últimos 14 dias e, só para Super Admin, tabela de faturação por
Admin.

Testes: `tests/Feature/Admin/BillingTest.php` (preço obrigatório para
confirmar, preço negativo recusado, entrega gratuita aceite, correção
restrita a Super Admin com auditoria, correção recusada sem preço
prévio, âmbito próprio vs. âmbito total em `/admin/billing`, pedidos
ainda em negociação nunca entram nos totais).

## Integração KambaSMS (Setembro 2026)

Fornecedor de SMS escolhido para produção. Ativa-se com
`SMS_PROVIDER=kamba` no `.env` (além de `mock`/`production` já
existentes) — ver `App\Services\Sms\KambaSmsProvider`.

**Endpoints reais**, confirmados na documentação completa deles
(`https://www.kambasms.ao/dashboard/docs` — a doc Postman inicial só
cobria autenticação genérica, não os endpoints em si):
- Base URL: `https://nexasms-api.onrender.com`.
- Autenticação: header `X-API-Key` em todos os pedidos (exceto
  `/otp/verify`, que é público).
- `POST /messages/send` — corpo `{ to, text }`. O Sender ID é
  automático, ligado à API key (definido uma vez no dashboard deles ao
  criar a chave, 3-11 caracteres alfanuméricos, não muda depois) —
  **não** se envia no corpo do pedido.
- `GET /messages` — histórico das últimas 100 mensagens.
- `GET /credits/balance` — saldo de créditos.
- Limites: 50 SMS/hora por conta, 100 pedidos/15min por IP; erro 402
  quando o saldo acaba.

**Descoberta importante ao ler a documentação completa**: a API da
Kamba **não tem nenhum endpoint de callback/webhook**. O que o suporte
comercial descreveu como "webhook que mostra o histórico de entrega"
era, na prática, o painel/endpoint de histórico (`GET /messages`) —
nunca um push deles para o nosso servidor. Isto até simplifica as
coisas: o KeruGás já tinha decidido só precisar de um gateway de SMS
**outbound** (nunca processamos SMS recebidos via este fornecedor — a
Central de Operações continua manual, por telefone físico), por isso
`KambaSmsProvider::verifyWebhook()` simplesmente **rejeita sempre**,
em vez de fingir uma verificação de assinatura que a API nem oferece.
Não há nenhum cenário legítimo em que a Kamba chame `/webhooks/sms`.

Configuração: `config/kerugas.php` → `sms.kamba.base_url` (env
`KAMBA_SMS_BASE_URL`) — reaproveita a `SMS_API_KEY` já existente
(header `X-API-Key`). `sms.kamba.sender_id` (env `KAMBA_SMS_SENDER_ID`)
é só informativo, para documentar qual Sender ID está associado à
chave em uso — nunca é enviado à API.

Testes: `tests/Unit/KambaSmsProviderTest.php` (envio com os campos e
header corretos, sender_id nunca enviado no corpo, falha tratada num
402 de saldo insuficiente, `verifyWebhook()` sempre rejeita sem sequer
chamar a API, consulta de saldo com falha fechada em erro de rede).

Utilitário extra (fora de `SmsProviderInterface`, específico deste
provedor): `KambaSmsProvider::creditsBalance()` — consulta
`GET /credits/balance`, útil para alertar antes de ficar sem créditos
em vez de descobrir a meio de um envio real a um cliente com um 402.

**Endpoint e botão no painel (Setembro 2026).** `GET /admin/sms/credits`
(`SmsDispatchController::credits()`) expõe `creditsBalance()` no
painel — **estritamente Super Admin**, verificado diretamente no
controller (não só pela permissão `sms.view`, que Admin também tem
para o histórico) porque é um dado financeiro da conta da plataforma
junto do fornecedor, mesmo padrão já usado em
`AdminOrderController::updatePrice()`. Devolve `supported: false` (sem
tentar nenhuma chamada de rede) quando o provedor ativo não é a Kamba
— `mock`/`production` não têm noção de créditos. Botão "Ver saldo" na
Central de Operações → Histórico (`SmsCreditsPanel`, só visível a
Super Admin), consulta sob pedido em vez de automática ao abrir a
página, já que é uma chamada real ao fornecedor externo. Testes:
`tests/Feature/Sms/SmsCreditsTest.php` (403 para Admin comum,
`supported=false` com `mock`, saldo real com `kamba` via `Http::fake`,
e falha de rede tratada com `balance: null` + mensagem).

**BUG CORRIGIDO — `env()` fora de um ficheiro de config (Setembro
2026).** `AppServiceProvider::register()` lia `SMS_API_KEY`/
`SMS_API_SECRET`/`SMS_WEBHOOK_SECRET` com `env()` diretamente, em vez
de `config()`. Isto funciona em desenvolvimento (sem cache), mas
rebenta em produção: depois de `php artisan config:cache` — passo
normal de deploy, que faz parte da própria checklist de ativação da
Kamba — o Laravel deixa de ler o `.env` do disco em runtime, e
qualquer `env()` chamado fora de um ficheiro `config/*.php` passa a
devolver sempre `null`. Sintoma exato: `KambaSmsProvider::__construct():
Argument #1 ($apiKey) must be of type string, null given`, e antes
disso — enquanto o cache ainda tinha `provider => mock` de uma versão
anterior do `.env` — um envio que parecia ter sucesso mas nunca saía
de verdade nem descontava saldo (era o `MockSmsProvider`, só a
registar no log). Corrigido: `SMS_API_KEY`/`SMS_API_SECRET`/
`SMS_WEBHOOK_SECRET` agora vivem em `config('kerugas.sms.api_key'
/'api_secret'/'webhook_secret')` (dentro de `config/kerugas.php`, que
o cache lê e guarda corretamente), e `AppServiceProvider` só lê por
`config()`. **Depois de atualizar este código em produção, é
obrigatório correr `php artisan config:clear && php artisan
config:cache` mais uma vez**, para o cache antigo (com os valores
errados) ser substituído.

## Conformidade de conteúdo SMS com a KambaSMS (Setembro 2026)

Ao ler o guia oficial deles ("Boas Práticas para o Sender ID —
KambaSMS Transacional"), confirmou-se um problema real nos templates
de SMS existentes — não hipotético, testado contra o conteúdo real do
código:

**As 5 mensagens da Central de Operações (`SmsTemplateSeeder`) e o
alerta de disponibilidade de gás (`DispatchStationAlertsListener`)
tinham DUAS violações que fariam a Kamba recusar o envio (erro 400,
sem descontar crédito, mas sem a mensagem sair)**:
1. Todos incluíam um link (`www.kerugas.com` ou `config('app.url')`) —
   qualquer SMS com `http://`, `https://`, `www.`, `.com` ou `.ao` é
   bloqueado automaticamente pela API deles.
2. Os 5 templates tinham entre 193 e 234 caracteres (limite é 160).

Corrigido: os 5 templates foram reescritos, mais curtos e sem
nenhum link (verificado por baixo de 160 caracteres mesmo com
variáveis preenchidas com valores realistas — ver
`tests/Feature/Sms/SmsTemplateComplianceTest.php`). O alerta de
disponibilidade também perdeu o link.

**Risco que continua a existir e como foi mitigado**: o template
`AVAILABILITY` tem uma variável `{{postos}}` sem limite natural (a
lista de postos com gás no município) — com muitos postos, voltaria a
ultrapassar 160 caracteres. `OperationService::formatStationsList()`
agora limita a lista a **2 postos**, acrescentando "+N noutros
postos." quando há mais — em vez de listar todos sem limite, como
antes. O template `STATION_INFO` inclui `{{localizacao}}` (texto
livre inserido pelo admin ao registar o posto), que continua sem
limite rígido — mitigado pelo facto de o admin já ver a contagem de
caracteres num preview antes de enviar (`SmsSegmentCounter`), não por
truncar sem avisar.

Testes: `tests/Feature/Sms/SmsTemplateComplianceTest.php` (nenhum
template tem link/URL, nenhum ultrapassa 160 caracteres com texto fixo
apenas, a lista de postos nunca tem mais de 2 entradas).

## Condutores, envio de pedidos e chat por município (Setembro 2026)

Pedido explícito do cliente, com 4 decisões de arquitetura confirmadas
antes de qualquer código ser escrito (ver histórico do projeto).

**1. Confirmação do(s) tipo(s) de garrafa.** `AdminOrderController::confirm()`
agora exige, no mesmo pedido que o preço, `cylinder_types_confirmed`
— texto livre, separado por vírgula quando há mais de uma garrafa/tipo
(ex.: "6kg, 13kg"). Guardado num campo próprio em `gas_orders`,
distinto de `cylinder_type` (a estimativa inicial e opcional do
cliente no formulário público).

**2. Novo papel: Condutor (`UserRole::DRIVER`).** Reaproveita
`users.province_id`/`municipality_id` já existentes como a
província/município FIXOS do condutor — só recebe pedidos do SEU
município. Um Admin/Super Admin, depois de finalizar um pedido
(`CONFIRMED`), escolhe um condutor DISPONÍVEL do MESMO município via
`POST /admin/orders/{order}/assign-driver` (`AdminOrderController::assignToDriver`,
que reaproveita o mecanismo `assigned_to`/`ASSIGNED` já existente em
`GasOrderService::assign()` em vez de criar um novo). `GET /admin/drivers`
(`DriverAdminController`) lista/filtra condutores por município — um
Admin só vê os da sua própria província, o Super Admin todos.

**3. Fluxo do condutor: direto, sem passo intermédio.** No dashboard
dedicado (`/driver`, `DriverOrderController`), um pedido atribuído vai
de `ASSIGNED` diretamente para `DELIVERED` ("Feita") ou `CANCELLED`
("Cancelado") — nunca passa por `OUT_FOR_DELIVERY` (esse estado
intermédio só existe no fluxo administrativo antigo). **Restrição de
papel explícita**: só o próprio condutor (`assigned_to === auth()->id()`)
ou o `SUPER_ADMIN` pode concluir/cancelar um pedido atribuído a um
condutor — um `ADMIN` comum é bloqueado (403) mesmo com a permissão
`orders.manage`, tanto no backend (`AdminOrderController::assertNotDriverLocked`)
como escondido na interface (`AdminOrders.tsx`).

**4. Disponibilidade: automática + manual.** `driver_available`
(nova coluna em `users`) fica `false` automaticamente ao ser atribuído
um pedido e volta a `true` automaticamente ao ser concluído/cancelado
(`GasOrderService::assign()`/`complete()`/`cancel()`) — o condutor
também pode alternar manualmente a qualquer momento
(`POST /driver/availability`), ex.: para se marcar indisponível por
fim de turno mesmo sem entrega em curso.

**5. Contas de Condutor: estritamente Super Admin.** Diferente da
regra geral ("Admin gere utilizadores da sua própria província"), a
criação/gestão de contas de Condutor é reservada ao `SUPER_ADMIN` —
decisão explícita do cliente. Nova rota `POST /admin/users`
(`UserAdminController::store()`, até agora só existia auto-registo
público) recusa (403) criar um papel privilegiado
(`ADMIN`/`SUPER_ADMIN`/`DRIVER`) a quem não for Super Admin — mesma
verificação já usada em `update()`, agora com `DRIVER` incluído em
`PRIVILEGED_ROLES`.

**6. Chat por município, tempo real via Reverb.** Admins, super admins
e condutores trocam mensagens tipo "alguém conhece o local X ou Y do
município Z?" (`ChatController`, tabela `chat_messages`, evento
`ChatMessageSent implements ShouldBroadcast`, canal privado
`municipality.{id}.chat` — autorização em `routes/channels.php`).
Âmbito de visibilidade (leitura E escrita, sempre imposto no backend,
nunca só no frontend):
- Condutor: só o seu próprio município.
- Admin: só a sua própria província, com filtro opcional por município.
- Super Admin: tudo, com filtro opcional por província e depois município.

Frontend: `AdminOrders.tsx` ganhou o campo de garrafas confirmadas no
modal de confirmação e um botão "Enviar a condutor"; `/admin/drivers`
(listagem + criação, botão só visível a Super Admin) e `/admin/chat`
(seletor de município + `ChatPanel`); `/driver` é o dashboard dedicado
do condutor (pedidos, disponibilidade, chat do seu município) —
`DriverGuard` restringe o acesso, `AdminGuard` redireciona um condutor
que tente aceder a `/admin`.

Testes: `tests/Feature/Driver/DriverAndChatTest.php` — cobre as 4
decisões confirmadas (criação de condutor só por Super Admin, envio
só ao mesmo município e só se disponível, conclusão/cancelamento só
pelo próprio condutor ou Super Admin com disponibilidade a
restaurar-se automaticamente, e o âmbito de visibilidade do chat nos
três papéis) mais a validação conjunta de preço + garrafas na
confirmação do pedido.

## Avaliação do condutor, estatísticas e exportação de entregas (Setembro 2026)

Pedido explícito do cliente: avaliação do condutor pelo cliente,
ranking/estatísticas simples (visível ao condutor, ao Admin e ao Super
Admin) e exportação de entregas por condutor, mais o fluxo
ponta-a-ponta testado. Reaproveita, de propósito, os mesmos padrões já
usados no resto da app (referência+telefone como prova de posse,
`ScopesToAdminProvince`, `StationStatusExportService` como template de
exportação), para não introduzir uma segunda forma de fazer a mesma
coisa.

**1. Avaliação do condutor (`driver_ratings`).** Rota PÚBLICA (sem
`auth:sanctum`) — a maioria dos clientes que pedem gás não tem conta,
e `GasOrderController::showByReference`/`GasOrderPolicy` exigem um
utilizador autenticado e dono do pedido, o que não serve aqui.
`DriverRatingController` usa referência do pedido + telefone do
cliente como prova de posse (o mesmo nível de verificação já usado
nesta app na Central de Operações), sempre normalizado com
`PhoneNumberService` dos dois lados antes de comparar:

- `GET /orders/reference/{reference}/rating?customer_phone=...` — diz
  se já pode avaliar, se já avaliou, e o nome do condutor (nunca
  expõe nada sem o telefone correto).
- `POST /orders/reference/{reference}/rating` — `rating` (1-5,
  obrigatório) + `comment` (opcional, até 500 caracteres). Só aceite
  quando o pedido está `DELIVERED`, foi entregue por um `DRIVER`
  (nunca outro tipo de atribuição antiga) e ainda não tem avaliação
  (`driver_ratings.gas_order_id` é único). Ambas as rotas usam o
  rate limiter dedicado `throttle:ratings` (10/min por IP + 5/min por
  referência do pedido — protege contra adivinhar o telefone certo
  por força bruta).

Frontend: página pública `/avaliar/:reference` (`RateDelivery.tsx`,
fora de qualquer guard) — pede o telefone, mostra elegibilidade, e o
formulário de estrelas + comentário.

**2. Ranking/estatísticas simples (`DriverStatsService`).** Mesmo
cálculo partilhado entre as duas rotas, para nunca divergir:

- `GET /driver/stats` — o próprio condutor vê as suas.
- `GET /admin/drivers/{driver}/stats` — Admin (só da sua própria
  província, 404 fora dela) e Super Admin (qualquer condutor),
  permissão `drivers.view`.

Devolve: `total_delivered`, `total_cancelled`, `cancellation_rate`
(%), `average_delivery_minutes` (da transição para `ASSIGNED`, via
`GasOrderEvent`, até `completed_at` — nunca desde a criação do
pedido, que mede a Central de Operações, não o condutor),
`average_rating` e `ratings_count` (de `driver_ratings`). Calculado
em PHP, não em SQL específico de um driver de BD (mesma decisão já
tomada nos outros serviços de estatísticas do projeto).

Frontend: cartões de estatísticas em `DriverDashboard.tsx` (as
próprias) e um painel expansível por condutor em `AdminDrivers.tsx`
(clique na linha do condutor).

**3. Exportação de entregas por condutor (`DriverDeliveryExportService`).**
Mesma estrutura de `StationStatusExportService` (CSV sempre
disponível com BOM UTF-8 e separador `;`; PDF via
`barryvdh/laravel-dompdf`, com erro explícito se o pacote não estiver
instalado) — só exporta pedidos `DELIVERED` com condutor atribuído,
para efeitos de pagamento/comissão.

`GET /admin/drivers/export` (`DriverAdminController::export`,
permissão `drivers.view`) — filtros `format` (csv/pdf), `period`
(dia/semana/mês) + `date`, e opcionalmente `driver_id`/`municipality_id`/
`province_id`. Mesmo âmbito por província de `StationExportController`:
um `ADMIN` só exporta a sua própria província (`province_id` sempre
forçado pelo backend, com a mesma proteção contra `0` ser tratado
como "sem filtro" via `!empty()`).

Frontend: painel de exportação em `AdminDrivers.tsx`, reaproveitando
o componente partilhado `ExportControls.tsx` (agora com `title`/
`description` opcionais, para o texto fazer sentido fora do contexto
de postos) com um filtro extra de condutor.

**4. Testes de fluxo ponta-a-ponta.**
`tests/Feature/Driver/DriverRatingStatsExportTest.php` cobre: as
regras de elegibilidade da avaliação (ainda não entregue, entregue
por não-condutor, telefone errado, já avaliado, nota fora de 1-5);
o cálculo e âmbito das estatísticas (condutor vê as suas, Admin só as
da sua província, Super Admin todas); a exportação (CSV com sucesso,
403 para Admin sem província); e um teste de ponta-a-ponta único que
percorre o fluxo inteiro — criar pedido → confirmar preço/garrafas →
atribuir a condutor → condutor conclui → cliente avalia (sem login)
→ estatísticas do condutor refletem a entrega e a nota → exportação
inclui a entrega.

## Monitorização e limite diário de SMS por Admin (Setembro 2026)

Pedido explícito do cliente, motivado por controlo de custo: a Central
de Operações envia SMS através de um gateway pago (KambaSMS), por isso
o Super Admin precisa de auditar quem enviou o quê e de poder limitar
quantos SMS cada Admin envia por dia.

### O que já existia e não precisou de mudar

Cada `SmsDispatch` já guardava `created_by` (o Admin autor), `message`
(o conteúdo exato enviado), `recipient` e `created_at` — a base de
dados já tinha tudo o que era preciso para auditoria, só faltava
expor isso corretamente e acrescentar o controlo de limite.

### Limite diário por Admin

- Novo campo `users.sms_daily_limit` (nullable — `null` = sem limite,
  comportamento anterior preservado para quem o Super Admin não
  configurar). Só o Super Admin pode defini-lo, em `/admin/users`
  (`UpdateUserAdminRequest::assertAdminCannotSetSmsDailyLimit()`).
- Verificado em `SmsDispatchService::dispatch()` — no serviço central,
  não no controller, para cobrir qualquer caminho futuro que venha a
  enviar SMS, não só a Central de Operações. Um SMS que atinja o
  limite nunca chega a ser criado (`SmsDailyLimitExceededException`).
- Um envio `FAILED` (recusado pelo provedor) **não conta** para a
  quota — não é culpa nem custo do Admin.
- `OperationController::send()` apanha a exceção e devolve **429**
  com mensagem clara ("Atingiu o seu limite diário de X SMS..."), e
  regista a tentativa bloqueada em `AuditLog` (`sms_daily_limit_blocked`)
  — o Super Admin vê não só quem enviou o quê, mas também quem tentou
  e foi travado.

### Visibilidade do histórico (auditoria real)

Antes, qualquer Admin com `sms.view` via o histórico completo de
**todos** os admins — o que anulava o propósito de um controlo de
custo (não faz sentido ser auditado por alguém que também consegue
ver/editar a própria auditoria). Agora:

- Um **Admin comum** só vê, em `/admin/sms/history` e `/admin/sms/stats`,
  os SMS que ele próprio enviou — mesmo que tente forçar
  `?created_by=<outro-id>` na query string (ignorado quando quem pede
  não é Super Admin).
- O **Super Admin** continua a ver o histórico de todos e pode filtrar
  por `created_by`.
- No frontend (`AdminOperationsCenter` → aba Histórico), a tabela já
  mostrava "Operador" (quem enviou) e "Data"; passou a ter uma linha
  expansível por SMS com o **conteúdo exato da mensagem**.

### Nova vista: uso de SMS por Admin

`GET /admin/sms/usage-by-admin` — estritamente Super Admin (mesmo
padrão de `credits()`: verificado dentro do controller, não só pela
permissão `sms.view`, que o Admin também tem). Devolve, por Admin:
enviados hoje, limite diário, restantes hoje, enviados este mês e
último envio. Renderizado como painel na aba Histórico
(`SmsUsageByAdminPanel`), só visível a Super Admin.

O limite diário de cada Admin edita-se em `/admin/users` (novo campo
"Limite SMS/dia" ao lado do papel, visível só ao Super Admin e só
para contas ADMIN) — não na própria página de operações, que é
deliberadamente só de leitura/auditoria.

### Testes

`tests/Feature/Sms/SmsDailyLimitAndAuditTest.php` cobre: bloqueio ao
atingir o limite (e ausência de bloqueio quando `null`), FAILED não
conta para a quota, a rota `/operations/{id}/send` devolve 429 com
`AuditLog` da tentativa bloqueada, escopo do histórico por role,
`usage-by-admin` estritamente Super Admin com contagens corretas, e
que um Admin comum não consegue alterar `sms_daily_limit` de ninguém.

## Formulário de Contacto — integração real (Setembro 2026)

O formulário público em `/contato` era só um protótipo de interface —
`onSubmit` fazia apenas `setSent(true)`, sem guardar nem enviar nada.
Agora é uma integração real:

- **`POST /api/v1/contact`** (público, `throttle:contact` = 5/hora por
  IP) — valida `name`/`contact`/`message`, guarda em `contact_messages`
  e só depois tenta enviar um email (`NewContactMessageMail`) para
  `CONTACT_RECIPIENT_EMAIL` (`.env`, por omissão `contacto@kerugas.com`).
- **A ordem importa**: guarda sempre primeiro, envia depois. Um `.env`
  de produção com SMTP mal configurado (o mesmo tipo de problema já
  visto com a Kamba) nunca faz perder a mensagem do visitante — ela
  fica visível em `/admin/contact-messages` mesmo que o email nunca
  chegue.
- **Email assíncrono**: `NewContactMessageMail implements ShouldQueue`
  — o `docker-compose.yml` já corre um worker dedicado (`queue`), por
  isso um SMTP lento nunca atrasa a resposta ao visitante.
- **Honeypot anti-spam**: campo `website`, escondido por CSS no
  frontend (nunca alcançável por Tab), que tem de vir vazio — mais
  barato que um captcha e suficiente para o volume esperado. A
  mensagem de erro nunca revela que é um honeypot.
- **Painel Admin** (`/admin/contact-messages`, `permission:contact.view`):
  lista todas as mensagens (mais recentes primeiro), filtro por
  lida/não lida, contagem de "por ler" no cabeçalho, e ação de marcar
  como lida. Sem âmbito por província — o formulário público não pede
  localização.
- **Acesso estritamente Super Admin** (decisão explícita do cliente,
  ao contrário da generalidade das secções do painel): `CONTACT_VIEW`
  não está na lista de permissões do ADMIN em `RolePermissions`, por
  isso só chega ao Super Admin (via o seu acesso total `'*'`). O link
  na sidebar também só aparece para Super Admin, e a própria página
  (`AdminContactMessages.tsx`) mostra um aviso em vez de tentar
  carregar dados caso um Admin comum aceda pelo URL diretamente.
- **Configuração necessária no `.env` de produção**: `MAIL_MAILER=log`
  (valor de desenvolvimento) nunca envia nada de verdade — só escreve
  em `storage/logs/laravel.log`. Definir `MAIL_MAILER=smtp` +
  `MAIL_HOST`/`MAIL_PORT`/`MAIL_USERNAME`/`MAIL_PASSWORD` reais antes
  do deploy, e `CONTACT_RECIPIENT_EMAIL` se o destino não for
  `contacto@kerugas.com`.

Testes em `tests/Feature/ContactMessageTest.php`: guarda + envia
email para o destinatário certo, honeypot bloqueia sem revelar o
motivo, validação de campos obrigatórios, mensagem fica guardada
mesmo com falha de SMTP, e âmbito de permissão do painel Admin.

## Varredura de bugs antes do app Android + notificações push (Setembro 2026)

Pedido do cliente: "antes de avançar, certifique-se de que todos os bugs
foram resolvidos". Como esta árvore não tem `composer.json`/`vendor` e o
Packagist está bloqueado no ambiente onde foi feita a revisão, a suite
Pest não pôde correr aqui. Em vez de ficar só pelo `php -l` (que deixou
escapar o bug do `ContactController`), a revisão usou verificações a sério:

- **PHPStan** sobre `app/`, `database/`, `routes/`, `config/`, `tests/`
  (classes, métodos e imports inexistentes);
- os **63 imports do framework** confrontados com a lista real de classes
  do Laravel 11 e do Sanctum;
- as **94 rotas** confrontadas com os controllers/métodos, as 22
  permissões, os 9 rate limiters e o route model binding de 43 rotas;
- os `$fillable`/`$casts` de cada model confrontados com as colunas das
  **27 tabelas** das migrations;
- as **87 chamadas** `api.get/post/...` do frontend confrontadas com as
  rotas reais (método + caminho);
- `nginx -t` e testes HTTP reais contra o `nginx.conf`;
- o app Android testado no Chromium a simular a plataforma Android.

### Bugs reais encontrados e corrigidos

1. **`$this->authorize()` rebentava** (`GasOrderController`): o
   `Controller` base do Laravel 11 vem vazio, sem `AuthorizesRequests`.
   Adicionado ao `app/Http/Controllers/Controller.php`, que também passa
   a fazer parte desta árvore (faltava).
2. **Pedidos de clientes com sessão iniciada ficavam sem dono**:
   `POST /orders` é pública, e sem `auth:sanctum` o `$request->user()`
   usa o guard `web` e devolve sempre `null` para Bearer tokens. "Os meus
   pedidos" vinha sempre vazio. Agora `$request->user('sanctum')`. Mesmo
   bug em `POST /analytics/events`.
3. **`GET /orders/reference/{ref}` respondia 403 a toda a gente**, incluindo
   o dono: estava fora do grupo autenticado. Movida para dentro.
   Os testes antigos não apanhavam 2 e 3 porque `Sanctum::actingAs()` muda
   o guard por omissão — os novos (`OrderOwnershipWithBearerTokenTest`)
   usam tokens Bearer reais.
4. **Tempo real privado nunca funcionou** (pedidos no painel Admin, chat):
   `/broadcasting/auth` era registado com o middleware `web` (sessão), e
   o nginx nem o encaminhava para o PHP. Agora `/api/broadcasting/auth`
   com `auth:sanctum` (`bootstrap/app.php`).
5. **Tempo real partia com o HTTPS**: o cliente usava `forceTLS: false`
   (ws:// numa página https:// é bloqueado). Agora wss:// pela porta 443,
   via nginx (`location /app/`), e o token é lido a cada autorização (não
   só uma vez no arranque). Novo serviço `reverb` no docker-compose.
6. **Build Docker do site apontava a API para `localhost`**: as `VITE_*`
   não chegavam ao `npm run build` (o `env_file` só vale em runtime).
   Agora são build args, com `/api/v1` relativo por omissão.
7. **404 ao abrir/recarregar qualquer página que não a inicial** no
   contentor do frontend (sem fallback de SPA) — incluindo o link de
   avaliação do condutor enviado por SMS.
8. **Sem `.dockerignore`**: o `node_modules` do Windows era copiado por
   cima do do contentor e o build falhava.
9. **Extensão `redis` do PHP em falta** na imagem, com filas, cache e
   sessões em Redis (`Class Redis not found`). E `CACHE_DRIVER` já não
   existe no Laravel 11 — passou a `CACHE_STORE`.
10. **Certificado HTTPS "expirava" apesar de renovado**: o nginx nunca
    recarregava o certificado renovado pelo certbot. Agora recarrega a
    cada 6h.
11. **Rate limits por IP incompatíveis com CGNAT** (operadoras móveis
    angolanas põem centenas de telemóveis atrás do mesmo IP):
    `public-read` passa a ser por conta quando há sessão (e mais largo por
    IP), e `auth` passa a limitar por conta/telefone (6/min) com um tecto
    largo por IP (60/min).
12. **Condutor nunca era avisado** de um pedido atribuído.
13. **Sessão "desaparecia" ao abrir uma página protegida diretamente**
    (frontend): o `AuthGuard` redirecionava para o login no primeiro
    render, antes de o token ser lido. E cada navegação "desconfirmava" a
    sessão. Ver `src/stores/authStore.ts`.
14. **Chat sem mensagens novas sem Reverb** — agora atualiza sozinho
    (10s sem tempo real, 60s de segurança com ele).

### Notificações push (app Android)

Cada `AppNotification` criada passa automaticamente a push para todos os
telemóveis do destinatário (`AppNotificationObserver` →
`SendPushForNotificationJob`, na fila, depois do commit). Assim os
alertas de disponibilidade e os avisos de novo pedido para admins
ganharam push sem mudar nada neles. Novas notificações:

- **Condutor**: "Novo pedido atribuído" (abre `/driver`).
- **Cliente com conta**: pedido confirmado, condutor a caminho, saiu para
  entrega, entregue, cancelado (abre `/pedido/{referência}`). Nunca avisa
  a pessoa da ação que ela própria fez.

Envio por **FCM HTTP v1** (`FcmPushSender`), sem pacotes novos: JWT RS256
assinado com a conta de serviço, token OAuth2 em cache 55 min, tokens de
telemóveis desinstalados apagados sozinhos. Sem `FCM_CREDENTIALS_PATH`,
fica o `NullPushSender` e nada muda.

**iPhone (Outubro 2026):** os telemóveis `platform = ios` não passam pelo
Firebase — o `SendPushForNotificationJob` entrega-os ao `ApnsPushSender`, que fala
direto com a Apple (APNs HTTP/2, JWT ES256 assinado com a chave `.p8`, em cache
40 min; `APNS_KEY_PATH`, `APNS_KEY_ID`, `APNS_TEAM_ID`, `APNS_BUNDLE_ID`,
`APNS_SANDBOX`). Tokens `BadDeviceToken`/`Unregistered`/410 são apagados. Sem essas
variáveis o iPhone funciona, só sem push. Atualização obrigatória por plataforma:
`X-App-Platform: ios` compara com `APP_IOS_MIN_VERSION`. Guia: `apps/web/IOS.md`.

Telemóveis: `POST /api/v1/devices` / `DELETE /api/v1/devices`. Cada
registo fica ligado à **sessão** com que foi feito — ao terminar sessão,
o telemóvel deixa de receber push dessa conta (telemóveis partilhados).
`sanctum:prune-expired` corre diariamente e leva consigo os registos de
sessões expiradas.

Testes: `tests/Feature/Push/DeviceTokenTest.php`,
`tests/Feature/Push/PushDispatchTest.php`, `tests/Unit/FcmPushSenderTest.php`,
`tests/Feature/GasOrder/OrderOwnershipWithBearerTokenTest.php`.

### No servidor, depois deste deploy
1. `php artisan migrate` (tabela `device_tokens`).
2. `.env`: `CACHE_STORE=redis` (em vez de `CACHE_DRIVER`), `REVERB_HOST=reverb`,
   `VITE_REVERB_*` (ver `.env.example`), e `FCM_CREDENTIALS_PATH` quando o
   Firebase estiver criado.
3. `docker compose build` (as imagens mudaram) e `docker compose up -d`.
4. `php artisan config:cache && php artisan route:cache`.

## Campanhas de notificações, envios do Super Admin e VAPID (Setembro 2026)

**Dois tipos de notificação, um só motor** (`CampaignService`):

- **Automáticas** (o sistema envia sozinho, agendadas em `routes/console.php`, hora de Angola): lembrete de gás (15–20 dias, aprende o ritmo de cada cliente), segundo lembrete, dica de segurança mensal, aniversário de conta, datas especiais, boas-vindas, pós-entrega, "sentimos a sua falta", resumo diário do Admin, lembrete ao operador, bom dia ao condutor. Por evento: "há gás perto de si" e "fila curta agora".
- **Manuais** (o Super Admin dispara em *Admin → Notificações → Enviar*): para clientes, condutores, operadores, admins ou todos, com filtro por província/município, agendamento, pré-visualização de alcance e custo de SMS. Só o Super Admin (`notifications.manage`).

Regras sempre aplicadas: silêncio 21h–08h, máx. 2 promoções/semana, preferências da pessoa (`/notificacoes`), nunca repetir (`dedupe_key`), conversão = pedido em 48h.

**SMS:** o lembrete de gás e outras campanhas levam SMS. A KambaSMS rejeita links e cobra por segmento (160 caracteres em GSM-7; um "ã" ou "ç" passa a 70 e duplica o custo). Por isso o `SmsTextNormalizer` converte os acentos antes de enviar — o que também corrige os SMS já existentes que saíam como 2 mensagens.

**Comandos:** `kerugas:campaign {chave}`, `kerugas:broadcasts-due`, `kerugas:vapid`.

### Ligar as chaves VAPID (notificações no navegador)

1. `php artisan kerugas:vapid` — gera o par de chaves.
2. Cole no `.env`: `PUSH_VAPID_PUBLIC_KEY`, `PUSH_VAPID_PRIVATE_KEY` e `PUSH_VAPID_SUBJECT=mailto:o-seu-email@kerugas.ao`. **A chave privada nunca vai para o Git.**
3. `composer require minishlink/web-push`
4. `php artisan migrate`, depois `php artisan config:cache`.
5. Os contentores `queue` e `scheduler` têm de estar a correr (`docker compose up -d --build`).

Não gere chaves novas depois de haver subscritores: os navegadores já inscritos deixam de receber.

**Correções desta versão:** listeners de eventos corriam duas vezes (event discovery + registo manual) — agora `withEvents(discover: false)`; endpoints Web Push limitados aos serviços reais dos navegadores (SSRF); envios em massa por fatias de 500 (a fila reexecutava jobs longos).

## Localização → província e município; templates só do Super Admin (Setembro 2026)

- **`GET /api/v1/territory/locate?latitude=&longitude=`** (público, `throttle:geocode` 20/min por IP): devolve `province_id`, `municipality_id` e `source`. Usa geocodificação inversa (Nominatim, feita pelo servidor, com cache de 30 dias) e faz a correspondência com os nossos territórios ignorando acentos, prefixos ("Província da…") e variações de ortografia (Kwanza/Cuanza, Quilamba/Kilamba). Se falhar, usa o município do posto ativo mais próximo (até ~30 km); se nem isso, devolve `null` e o utilizador escolhe. O registo e o pedido de gás preenchem os selects a partir daqui — o utilizador pode alterá-los, e uma escolha manual nunca é sobrescrita. Configuração opcional: `KERUGAS_GEOCODER_URL` e `KERUGAS_GEOCODER_USER_AGENT` (o servidor precisa de sair para a internet por HTTPS).
- **Templates de SMS da Central de Operações:** o Admin continua a vê-los e a usá-los para responder, mas só o Super Admin os cria, edita ou desativa (`sms.templates.manage` saiu do papel ADMIN).

## O que falta (próximas etapas)

- Canais de notificação adicionais (email/push para alertas e pedidos — hoje só in-app + SMS opcional) — Etapa 14b
- Analytics avançado (gráficos, mapa de calor de procura) — Etapa 15
- Real-time via WebSockets (Laravel Reverb) — hoje as atualizações de posto já disparam eventos `ShouldBroadcast`, falta o servidor Reverb e o listener no frontend — Etapa 16
- Verificação de email/telefone (email_verified_at já existe no schema) — Etapa 4b

Consulte o `README.md` da raiz do monorepo para o roadmap completo.

## Pedidos do cliente, comentários, Central e promoção de utilizadores (Outubro 2026)

- **Botão de pedido (cliente):** o botão flutuante "Acompanhar pedido" existe enquanto há um pedido em curso; depois da entrega por um condutor passa a "Avaliar o condutor" (some ao avaliar, ao dispensar ou após 7 dias). Visitantes sem conta acompanham por `GET /orders/reference/{ref}/track?customer_phone=` (referência + telefone, `throttle:ratings`; só devolve campos seguros).
- **"Os meus pedidos":** `GET /orders?filter=active|delivered|cancelled` (cancelados inclui falhados); link no cabeçalho e no painel do cliente.
- **Moderação de comentários:** cada comentário traz o posto (`station.name/municipality/province`) para o moderador ver antes de aprovar.
- **Central de Operações:** um Admin atende só na sua província — o ecrã preenche-a e só lista os municípios dela; `StoreOperationRequest` força o mesmo no servidor (município tem de pertencer à província; Admin sem província → 403).
- **Promover utilizadores:** mudar o papel de alguém (Moderador/Operador) ou criar contas com esses papéis exige `users.can_promote_users = true` no Admin. **Desligado por omissão**; só o Super Admin o liga/desliga (em `/admin/users`, por Admin). Continua proibido a Admin promover a Admin/Super Admin/Condutor. Migração: `2026_10_02_000001_add_can_promote_users_to_users_table` (`php artisan migrate`).
- **Última atualização dos postos:** o site mostra o tempo exato ("Última atualização há 5 horas e 9 minutos"), calculado no navegador a partir de `status_updated_at`.

## Avisos push dos pedidos e painel de métricas (Outubro 2026)

**Avisos dos pedidos.** Cliente com conta: cada passo do pedido cria uma notificação e sai push (Web Push ou Firebase) — já existia. Novo:
- **Cliente sem conta** (a maioria): em "Pedido recebido" e no acompanhamento aparece "Receber avisos deste pedido". Subscreve só esse pedido (`POST /orders/reference/{ref}/push`, prova = referência + telefone, `throttle:ratings`). As subscrições ficam em `order_push_subscriptions` e apagam-se no estado final (entregue/cancelado) ou com o pedido. Corre `php artisan migrate`.
- **Equipa**: um novo pedido avisa só os Admins **da província do pedido** e os Super Admins (antes: todos os Admins). Cliente ou condutor que cancelam avisam a equipa; o Admin que cancela um pedido atribuído avisa o condutor; "a contactar" avisa o cliente.
- Requer Web Push ativo: `composer require minishlink/web-push` + `php artisan kerugas:vapid` e as chaves no `.env`. Sem isso o servidor responde 503 a `/push` e o botão não aparece. No app Android, quem não tem conta não recebe push (precisa de conta/Firebase).

**Métricas do Super Admin.** `GET /api/v1/admin/metrics?days=7|30|90` (só `SUPER_ADMIN`) e página `/admin/metrics`. Definições dos números no topo de `SuperAdminMetricsController` (pedidos = coorte criada no período; tempos em minutos; tendência face ao período anterior).

## Arranque em produção (primeira vez)

```bash
php artisan migrate --force          # cria as tabelas
php artisan db:seed --force          # províncias, municípios e modelos de SMS (NÃO cria contas demo em produção)
php artisan kerugas:create-super-admin   # pede nome, telemóvel e palavra-passe (oculta)
php artisan kerugas:vapid            # chaves de notificações (copiar para o .env)
```

Entre no site/app com o telemóvel e a palavra-passe do Super Admin. Todos os dados
vivem na base de dados do servidor; a app móvel apenas consome a API.

## Subscrições mensais (Outubro 2026)

Dois tipos, ambos com **ativação manual** pelo Super Admin (pagamento por transferência
bancária; o comprovativo chega por WhatsApp):

- **Consulta de gás por SMS** (cliente, por telemóvel): `SMS_CONSULT`.
- **Analytics do posto** (operador): `STATION_ANALYTICS`.

**Limite de consultas grátis.** A consulta por SMS é um atendimento manual da Central. Conta como
consulta gasta um atendimento **enviado** com um template de consulta (disponibilidade, sem gás, info
de posto) no mês civil (hora de Angola). Com o interruptor ligado, a partir de 3 consultas (configurável)
o telemóvel só recebe resposta de consulta com subscrição ativa — o servidor responde `402` com
`code: SUBSCRIPTION_REQUIRED` e a Central sugere o template de subscrição. **Vem desligado** por omissão.

**Templates novos** (Central de Operações, sem links, ≤160 carateres, sem preço):
`SUBSCRIPTION_REQUIRED`, `SUBSCRIPTION_EXPIRED`, `SUBSCRIPTION_ACTIVATED`, `OPERATOR_ANALYTICS`.
Variáveis novas: `{{contacto}} {{iban}} {{valor}} {{validade}} {{limite}}` — `{{valor}}` só tem texto
quando o preço estiver definido.

**Operador com analytics desativado.** Ao desativar/ativar o analytics, o operador recebe **automaticamente**
uma notificação na plataforma (e push). O SMS ao operador é **manual**: botão na lista de Postos (Super Admin).
Um comando diário (`kerugas:subscriptions-check`, 09:20) lembra o operador 7 dias antes de a subscrição terminar.

Endpoints (Super Admin): `GET/POST /admin/subscriptions`, `POST /admin/subscriptions/{id}/cancel`,
`GET /admin/subscriptions/lookup?phone=`, `GET/PUT /admin/subscriptions/settings`,
`POST /admin/stations/{id}/subscription-notice`. Operador: `GET /operator/station/subscription`.
Central: `GET /admin/operations/{id}/subscription`.

Para pôr no ar:

```bash
php artisan migrate --force
# Atenção: este seeder repõe o texto dos templates base pelo `code`.
# Se editou algum template na Central, copie o texto antes.
php artisan db:seed --class=SmsTemplateSeeder --force
```

Defina o contacto que aparece nos SMS (`KERUGAS_CENTRAL_PHONE` no `.env`, ou nas Definições da página
Subscrições) e o IBAN nas Definições.

## Quero Gás intermediária: pedidos, carregamentos, stock e faturação (Outubro 2026)

A Quero Gás ainda não tem posto. O condutor vai a um posto buscar garrafas (**carregamento**) e fica com elas em **stock**; os pedidos saem desse stock — **o pedido não tem posto**.

**Pedido**
- Confirmar (Admin/Super Admin): *valor das garrafas* (total do pedido) + *comissão* (negociada caso a caso, pode ser 0) + tipo(s) de garrafa. `delivery_price` = soma, calculada no servidor; o cliente só vê o total. As garrafas (`quantity`) só sobem.
- Pedidos antigos (só total): "Por completar" na Faturação (`PATCH /admin/orders/{order}/billing`) — garrafas + divisão que some exatamente o total.
- Correção (só Super Admin, `PATCH /admin/orders/{order}/price`): recalcula o total, auditado.

**Carregamentos e stock**
- Condutor: `GET /driver/stock` ("Garrafas disponíveis"), `GET /driver/stations`, `POST /driver/pickups` (posto, garrafas, valor a pagar ao posto; idempotente por `client_uuid`). **Sem internet**: a app guarda numa fila no telemóvel e envia sozinha quando a rede voltar.
- Operador (aba "Garrafas KeruGás"): `GET /operator/pickups`, `POST /operator/pickups/{id}/confirm`, `POST /operator/pickups/{id}/dispute`. Sem confirmação fica "só condutor" e conta na mesma; contestar avisa o Super Admin.
- Stock = carregado − entregue; disponível = em mão − reservado (pedidos atribuídos por entregar). **Sem garrafas suficientes não se envia o pedido ao condutor nem se confirma a entrega** (vale também para o Super Admin). As que sobram ficam com o condutor.
- Admin/Super Admin: página "Carregamentos" (`GET /admin/pickups`), corrigir valor até estar pago (`PATCH /admin/pickups/{id}`, auditado), stock de cada condutor na Faturação e na escolha do condutor.
- Contador do posto: garrafas levadas desde o último reinício **manual** do Super Admin (`POST /admin/stations/{station}/counter-reset`); histórico em `station_counter_resets`.

**Faturação** (`GET /admin/billing?date=`)
- Pedidos: só ENTREGUES, pelo dia da entrega (dia civil de Luanda). **Cada Admin vê só os pedidos que ele confirmou**; o Super Admin vê tudo + por Admin.
- Postos: o que se deve vem dos **carregamentos**. "Pago ao posto" (`POST /admin/billing/stations/{station}/pay`) é tudo ou nada, fica no livro e avisa o Super Admin; só o Super Admin desfaz.
- Condutores (da província do Admin): dinheiro por entregar (todos os pedidos dele) + stock. "Recebi X" parcial (`POST /admin/billing/drivers/{driver}/receipts`), nunca acima do em dívida; correções por estorno (Super Admin).

**Popup das notificações**: tocar numa notificação sem ecrã próprio (sino, push do telemóvel ou do navegador) abre a mensagem completa num popup (`GET /notifications/{id}`; a push abre `/?notificacao=ID`).

No servidor: `php artisan migrate` (migrações `2026_10_05_000001`, `000002`, `000003`), depois `php artisan test`. **Antes de pôr em produção**: cada condutor tem de registar o seu primeiro carregamento, senão não consegue confirmar entregas.

## Super Admin Provincial (Outubro 2026)

Novo papel `PROVINCE_SUPER_ADMIN` ("Super Admin Provincial"): os poderes do Super Admin, **só na província ligada à conta** (`users.province_id`, obrigatória). Pode haver vários por província. Só o Super Admin geral cria, altera ou retira este papel (em Utilizadores: escolher o papel + a província).

**Pode (só na sua província):** gerir todos os pedidos, corrigir valores, marcar feito/cancelado pedidos de condutor; faturação de todos os Admins da província (+ detalhe por Admin), pagar postos, receber dinheiro, ver e **desfazer** o livro da província; carregamentos e **reiniciar contadores** dos postos da província; criar/editar postos, atribuir operadores, ligar o analytics, SMS de subscrição ao operador; **ativar/cancelar subscrições de postos**; criar e gerir Admins (incl. permissão de promover e limite de SMS), Condutores, Operadores e Moderadores; **métricas** filtradas; chat de todos os municípios; Central de Operações e exportações; **enviar notificações** aos utilizadores da província (a província é imposta pelo servidor); histórico de SMS das contas da província.

**Não pode:** nada de outras províncias; criar/alterar contas de Super Admin (geral ou provincial); mudar a província de alguém; preço/IBAN/contacto das subscrições, subscrições SMS de clientes; templates de SMS; campanhas automáticas; mensagens de contacto do site; saldo do provedor de SMS.

**Avisos:** ações numa província avisam os Super Admins gerais **e** os Provinciais dessa província (nunca quem fez a ação). Pedidos novos também chegam ao Provincial da província.

**Correções de segurança feitas ao mesmo tempo:**
- `role:A,B` nas rotas só aceitava o primeiro papel (`EnsureRole` agora aceita vários).
- Rotas do cliente (`GET /orders/reference/{ref}`, `PATCH /orders/{id}/cancel`) deixavam qualquer Admin ver/cancelar pedidos de outra província — e um Admin cancelar um pedido já entregue a condutor.
- Ações sobre atendimentos da Central (`/admin/operations/{id}/...`) não verificavam a província.
- Ao criar/editar um condutor, o município tem de pertencer à província.

Sem migração nova (o papel é uma string). Depois do deploy: `php artisan test`.

## Relatórios da faturação e mapa de calor da procura (Outubro 2026)

**Relatórios** (`GET /admin/billing/export?type=…&format=csv|pdf`), na página Faturação › Relatórios. Mesmo âmbito da Faturação (Admin: pedidos que confirmou + condutores/postos da província; Provincial: a província; Super Admin geral: tudo).
- `type=day&date=AAAA-MM-DD` — **Fecho do dia**: entregas (garrafas, comissão, total), carregamentos nos postos, condutores (cobrado, entregue, em falta, garrafas).
- `type=stations` — **Dívida aos postos**: resumo por posto + cada carregamento por pagar.
- `type=drivers&from=&to=` — **Dinheiro dos condutores** no período (por omissão, o mês corrente) + recebimentos.
- "Excel" é CSV com acentos e `;` (abre direto no Excel). PDF precisa de `composer require barryvdh/laravel-dompdf` (sem ele o servidor responde 503 com a explicação).

**Procura** (`GET /admin/analytics/demand?days=7|30|90&province_id=&municipality_id=`), página Admin › Procura:
- mapa de calor dos pedidos (células de ~110 m — nunca a morada exata), com os postos por cima;
- grelha dia da semana × hora (hora de Angola) e hora/dia de pico;
- bairros com mais pedidos (texto do cliente, normalizado — cobre também pedidos sem GPS) e municípios.
- Conta todos os pedidos feitos no período, incluindo cancelados. Admin e Provincial só veem a sua província.

## Meu Perfil (Outubro 2026)

Página `/perfil` para **todos os papéis** (link no cabeçalho, no menu do app e no painel admin).

- `PUT /users/me` — nome, telemóvel, email, província/município e localização exata (pode remover). Atua SEMPRE na conta da sessão (nunca recebe id). Lista fechada de campos: papel, estado, permissões, limite de SMS, etc. nunca mudam por aqui.
- **Telemóvel/email** (identificadores de login) exigem a palavra-passe atual; telemóvel normalizado e único; email único; ao mudar, perde a marca de verificado e a conta recebe um aviso de segurança.
- **Admin, Super Admin Provincial e Condutor** não mudam a própria província/município (é a área de trabalho) — só o Super Admin, em Utilizadores.
- `PUT /users/me/password` — atual + nova (mín. 8, letras e números, confirmada, diferente da atual). Termina as **outras** sessões (e os push desses dispositivos); esta continua. Aviso de segurança.
- `GET /users/me/sessions` / `DELETE /users/me/sessions/others` — sessões abertas (sem expor tokens) e terminar as outras.
- Proteções: 5 palavras-passe erradas na confirmação = 15 min de bloqueio por conta; rate limit nas rotas; tudo no `audit_logs` (`profile.updated`, `profile.password_changed`, `profile.sessions_ended`) sem nunca guardar a palavra-passe.

## Vizinhos — partilhar a localização (Outubro 2026)

Na conversa dos Vizinhos, o botão **Localização** partilha um ponto (posição do GPS) ou o **posto** onde a pessoa está (≤ 300 m; a posição exata não é guardada). Sem texto. Todos os vizinhos do município veem um mini-mapa com **Traçar rota** (Google Maps).

- **Horário:** só das 08h às 18h (hora de Angola, `kerugas.community_location`); qualquer partilha acaba às 18h.
- **Duração:** 30 min, 1 h ou 2 h, à escolha. Quem partilhou pode **parar** antes (`POST /community/messages/{id}/location/stop`); uma partilha nova termina a anterior.
- **Segurança:** a API só devolve coordenadas enquanto a partilha está ativa e a mensagem visível. Quando acaba, `kerugas:community-locations-prune` (a cada 5 min) **apaga** `location_lat/lng/accuracy/station_id` — a mensagem fica com o aviso. Apagar a mensagem ou a moderação removê-la também apaga as coordenadas. O backup corre a limpeza antes de copiar a base de dados.
- **Limites:** GPS com precisão pior que 500 m é recusado; 6 partilhas por hora; coordenadas fora de Angola recusadas.
- **Tempo real:** evento `community.location_ended` (só o id) e `GET /community/locations/active` como rede de segurança.
- Rotas: `POST /community/location`, `POST /community/messages/{id}/location/stop`, `GET /community/locations/active`. Migração: `2026_10_10_000001_add_location_share_to_community_messages`. Testes: `tests/Feature/Community/LocationShareTest.php`.
"# kerugas" 
"# kerugas" 
