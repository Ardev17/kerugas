<?php

namespace App\Services\Sms;

use App\Enums\NotificationCategory;
use App\Enums\StationStatus;
use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\BottlePickup;
use App\Models\Station;
use App\Models\User;
use App\Services\PhoneNumberService;
use App\Services\Stock\PickupService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Carregamento pedido por SMS, registado pela central (Outubro 2026).
 *
 * O fornecedor de SMS NÃO recebe mensagens: o SMS do condutor
 *   CARREGAR <quantidade> <número do posto> <PIN>
 * cai no telemóvel da central e quem atende cola o texto no painel
 * (junto com o número de quem enviou). O servidor então:
 *  - acha o condutor pelo número (só da província de quem regista);
 *  - confere o PIN pessoal do condutor (cifrado; bloqueia 1 h após 5 erros)
 *    — é isto que prova que a mensagem veio mesmo dele;
 *  - regista como se o condutor o tivesse feito (origem SMS): o posto
 *    confirma como habitualmente e conta para pagar como qualquer registo
 *    do condutor.
 *
 * PIN provisório: o PIN definido pelo admin ao criar a conta fica provisório
 * até o condutor o mudar na app. Enquanto o for, o admin também o sabe e
 * podia forjar um SMS — por isso estes registos só contam para pagar depois
 * de confirmados pelo condutor, pelo posto ou por um Super Admin.
 *
 * Não precisa do interruptor "registo pela central": o condutor tem
 * telemóvel e prova-o com o PIN. O PIN nunca é guardado nem registado.
 */
class SmsPickupService
{
    public function __construct(
        private readonly SmsCommandParser $parser,
        private readonly PickupService $pickups,
        private readonly PhoneNumberService $phones,
        private readonly SmsProviderInterface $provider,
    ) {
    }

    public function register(Request $request, array $data): BottlePickup
    {
        $actor = $request->user();
        abort_unless($actor->isAdmin(), 403);
        $cfg = config('kerugas.pickups');

        $cmd = $this->parser->parsePickup($data['sms_text']);
        abort_if($cmd === null, 422, 'Texto do SMS inválido. Esperado: CARREGAR <quantidade> <número do posto> <PIN>.');

        $phone = $this->phones->normalize($data['sender_phone']);
        abort_if($phone === null, 422, 'Número de telefone inválido.');

        $driver = User::where('role', UserRole::DRIVER)->where('status', 'ACTIVE')->where('phone', $phone)->first();
        // Mesma resposta para "não existe" e "é de outra província": não revela quem existe.
        abort_unless($driver && $actor->canAccessProvince($driver->province_id), 422, 'Não há nenhum condutor da sua província com este número.');

        if ($driver->sms_pin_locked_until && $driver->sms_pin_locked_until->isFuture()) {
            abort(422, 'O PIN deste condutor está bloqueado por tentativas falhadas. Tente mais tarde.');
        }
        abort_unless($driver->sms_pin_hash, 422, 'Este condutor ainda não definiu o PIN de SMS na app.');

        if (!Hash::check($cmd['pin'], $driver->sms_pin_hash)) {
            $fails = (int) $driver->sms_pin_failures + 1;
            $locked = $fails >= (int) $cfg['pin_max_failures'];
            $driver->forceFill([
                'sms_pin_failures' => $locked ? 0 : $fails,
                'sms_pin_locked_until' => $locked ? now()->addMinutes((int) $cfg['pin_lock_minutes']) : null,
            ])->save();
            $this->audit($request, $driver, 'pickup.sms_pin_failed', ['failures' => $fails, 'locked' => $locked]);

            abort(422, $locked ? 'PIN errado. Ficou bloqueado por segurança.' : 'O PIN do SMS não está certo — não registe.');
        }

        if ($driver->sms_pin_failures > 0 || $driver->sms_pin_locked_until) {
            $driver->forceFill(['sms_pin_failures' => 0, 'sms_pin_locked_until' => null])->save();
        }

        abort_if($cmd['quantity'] < 1 || $cmd['quantity'] > (int) $cfg['sms_max_quantity'], 422, "Quantidade fora do limite (1 a {$cfg['sms_max_quantity']}).");

        $today = BottlePickup::where('driver_id', $driver->id)->where('source', BottlePickup::SOURCE_SMS)->where('created_at', '>=', now()->startOfDay())->count();
        abort_if($today >= (int) $cfg['sms_per_day'], 422, 'Limite diário de carregamentos por SMS deste condutor atingido.');

        $station = Station::where('code', $cmd['station_code'])->where('status', StationStatus::ACTIVE)->first();
        abort_unless($station, 422, "O posto {$cmd['station_code']} não existe ou não está ativo.");

        $when = isset($data['received_at']) ? Carbon::parse($data['received_at']) : now();
        abort_if($when->isFuture(), 422, 'A hora do SMS não pode estar no futuro.');
        abort_if($when->lt(now()->subHours((int) $cfg['central_max_hours_back'])), 422, "Só se registam SMS das últimas {$cfg['central_max_hours_back']} horas.");

        $amount = isset($data['amount']) && $data['amount'] !== '' ? round((float) $data['amount'], 2) : $this->estimateAmount($station, $cmd['quantity']);

        [$pickup] = $this->pickups->register($driver, [
            'client_uuid' => (string) Str::uuid(),
            'station_id' => $station->id,
            'quantity' => $cmd['quantity'],
            'amount' => $amount,
            'registered_at' => $when->toIso8601String(),
            'offline' => true,
            'source' => BottlePickup::SOURCE_SMS,
            'registered_by' => $actor->id,
            // PIN ainda provisório (definido pelo admin): só conta para pagar depois de confirmado.
            'needs_confirmation' => (bool) $driver->sms_pin_temporary,
        ]);

        $this->audit($request, $driver, 'pickup.sms_registered', [
            'pickup_id' => $pickup->id, 'station_id' => $station->id, 'quantity' => $pickup->quantity, 'amount' => $amount,
        ]);
        $this->tell($driver, $pickup, $station);

        return $pickup->load(['station', 'driver', 'confirmer', 'registrar']);
    }

    /**
     * O SMS não leva valor: usa-se o preço por garrafa do último carregamento
     * nesse posto. Se não houver, fica 0 e o Admin corrige (auditado) antes de pagar.
     */
    private function estimateAmount(Station $station, int $quantity): float
    {
        $last = BottlePickup::where('station_id', $station->id)->where('quantity', '>', 0)->where('amount', '>', 0)
            ->latest('registered_at')->first();

        return $last ? round(((float) $last->amount / (int) $last->quantity) * $quantity, 2) : 0.0;
    }

    private function audit(Request $request, User $driver, string $action, array $new): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => $action, 'entity_type' => User::class, 'entity_id' => $driver->id,
            'old_values' => null, 'new_values' => $new, 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);
    }

    /** Avisa o condutor na app e, se o fornecedor deixar enviar, por SMS (melhor esforço). */
    private function tell(User $driver, BottlePickup $pickup, Station $station): void
    {
        try {
            AppNotification::create([
                'user_id' => $driver->id, 'category' => NotificationCategory::SYSTEM, 'title' => 'Carregamento registado por SMS',
                'body' => "A central registou o seu SMS: {$pickup->quantity} garrafa(s) no posto {$station->name}."
                    . ($pickup->needs_confirmation ? ' Confirme na app (o seu PIN ainda é o provisório — mude-o).' : ' O posto vai confirmar.'),
                'data' => ['type' => 'pickup', 'path' => '/driver'],
            ]);
            $this->provider->send($driver->phone, "KeruGas: registado {$pickup->quantity} garrafa(s) no posto {$station->name} ({$station->code}). O posto vai confirmar.");
        } catch (Throwable $e) {
            Log::warning('[SmsPickupService] aviso ao condutor falhou', ['driver' => $driver->id, 'error' => $e->getMessage()]);
        }
    }
}
