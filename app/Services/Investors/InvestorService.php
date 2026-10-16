<?php

namespace App\Services\Investors;

use App\Enums\GasOrderStatus;
use App\Enums\NotificationCategory;
use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\GasOrder;
use App\Models\Municipality;
use App\Models\MunicipalityInvestment;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Investidores por município (Outubro 2026).
 *
 * QUEM VÊ O QUÊ — regras fixas:
 *  1. O investidor só vê municípios com uma associação EM VIGOR em seu nome.
 *     Pedir outro município dá 403 (nunca "zero"), para não haver dúvidas.
 *  2. Dentro desse município só contam os pedidos ENTREGUES nos períodos em
 *     que ELE era o investidor (desde a data de associação). O que aconteceu
 *     antes de ele entrar, ou depois de sair, não lhe aparece.
 *  3. A sua parte = percentagem × comissão do KeruGás, calculada período a
 *     período (mudar a percentagem só vale daí para a frente).
 *  4. Só o Super Admin GERAL associa, muda a percentagem ou retira. Um
 *     município tem no máximo um investidor em vigor; um investidor pode
 *     ter vários municípios.
 *  5. Retirado de todos os municípios, a conta volta a Utilizador.
 */
class InvestorService
{
    public const MAX_RANGE_DAYS = 400;

    /** Papéis que podem ser promovidos a investidor (nunca contas de gestão, condutores ou operadores). */
    public const PROMOTABLE = [UserRole::USER, UserRole::INVESTOR];

    public function timezone(): string
    {
        return config('kerugas.notifications.timezone', 'Africa/Luanda');
    }

    // ── Associações (só Super Admin geral) ──────────────────────────────

    public function assign(User $actor, User $investor, int $municipalityId, float $percentage, ?string $startsOn = null): MunicipalityInvestment
    {
        $this->assertGlobalSuper($actor);
        $this->assertPercentage($percentage);

        if (!in_array($investor->role, self::PROMOTABLE, true)) {
            throw ValidationException::withMessages(['user_id' => 'Só uma conta de Utilizador (ou que já é Investidor) pode ser investidor. Contas de gestão, condutores e operadores não.']);
        }
        if ($investor->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['user_id' => 'A conta não está ativa.']);
        }
        if ($investor->investor_deactivated_at !== null) {
            throw ValidationException::withMessages(['user_id' => 'Este investidor está desativado. Reative-o primeiro.']);
        }

        $municipality = Municipality::query()->findOrFail($municipalityId);
        $startsAt = $this->startOf($startsOn);

        $row = DB::transaction(function () use ($actor, $investor, $municipality, $percentage, $startsAt) {
            $current = MunicipalityInvestment::query()->active()->where('municipality_id', $municipality->id)->lockForUpdate()->first();
            if ($current) {
                $name = $current->user_id === $investor->id ? 'esta pessoa' : (User::query()->whereKey($current->user_id)->value('name') ?? 'outra pessoa');
                throw ValidationException::withMessages(['municipality_id' => "O município {$municipality->name} já tem investidor ({$name}). Retire-o primeiro."]);
            }

            // Nunca dois investidores no mesmo pedido: não pode começar antes de o anterior sair.
            $lastEnd = MunicipalityInvestment::query()->where('municipality_id', $municipality->id)->max('ends_at');
            if ($lastEnd && $startsAt->lessThan(Carbon::parse($lastEnd))) {
                throw ValidationException::withMessages(['starts_on' => 'A data de início não pode ser antes de ' . $this->day(Carbon::parse($lastEnd)) . ' (quando terminou o investidor anterior deste município).']);
            }
            if ($startsAt->greaterThan(now()->addDay())) {
                throw ValidationException::withMessages(['starts_on' => 'A data de início não pode ser no futuro.']);
            }

            $row = MunicipalityInvestment::create([
                'municipality_id' => $municipality->id, 'user_id' => $investor->id,
                'percentage' => round($percentage, 2), 'starts_at' => $startsAt, 'assigned_by' => $actor->id,
            ]);

            if ($investor->role !== UserRole::INVESTOR) {
                $investor->forceFill(['role' => UserRole::INVESTOR, 'can_promote_users' => false])->save();
            }

            return $row;
        });

        $this->audit($actor, 'investor.assigned', $row, ['percentage' => $row->percentage, 'starts_at' => $row->starts_at->toIso8601String()]);
        $this->notify($investor->id, 'Foi associado como investidor',
            "Município {$municipality->name}: {$this->pct($row->percentage)} da comissão do KeruGás, a contar de {$this->day($row->starts_at)}.");

        return $row;
    }

    /** Nova percentagem: fecha o período atual e abre outro (o passado não muda). */
    public function changePercentage(User $actor, User $investor, int $municipalityId, float $percentage): MunicipalityInvestment
    {
        $this->assertGlobalSuper($actor);
        $this->assertPercentage($percentage);

        [$old, $new] = DB::transaction(function () use ($actor, $investor, $municipalityId, $percentage) {
            $current = $this->currentOrFail($investor, $municipalityId);
            if (abs($current->percentage - $percentage) < 0.001) {
                throw ValidationException::withMessages(['percentage' => 'A percentagem é igual à atual.']);
            }
            $now = now();
            $current->forceFill(['ends_at' => $now, 'end_reason' => 'percentage_changed', 'ended_by' => $actor->id])->save();
            $new = MunicipalityInvestment::create([
                'municipality_id' => $municipalityId, 'user_id' => $investor->id,
                'percentage' => round($percentage, 2), 'starts_at' => $now, 'assigned_by' => $actor->id,
            ]);

            return [$current, $new];
        });

        $this->audit($actor, 'investor.percentage_changed', $new, ['from' => $old->percentage, 'to' => $new->percentage]);
        $this->notify($investor->id, 'A sua percentagem foi atualizada',
            "Município {$new->municipality?->name}: passa de {$this->pct($old->percentage)} para {$this->pct($new->percentage)} a partir de agora. O que já foi calculado mantém-se.");

        return $new;
    }

    public function remove(User $actor, User $investor, int $municipalityId): void
    {
        $this->assertGlobalSuper($actor);

        $row = DB::transaction(function () use ($actor, $investor, $municipalityId) {
            $current = $this->currentOrFail($investor, $municipalityId);
            $current->forceFill(['ends_at' => now(), 'end_reason' => 'removed', 'ended_by' => $actor->id])->save();
            $this->demoteIfIdle($investor);

            return $current;
        });

        $this->audit($actor, 'investor.removed', $row, ['percentage' => $row->percentage]);
        $this->notify($investor->id, 'Associação de investidor terminada',
            "Deixou de ser o investidor do município {$row->municipality?->name}.");
    }

    /** Termina todas as associações (conta mudou de papel ou foi eliminada). */
    public function endAll(User $investor, ?User $actor = null, string $reason = 'role_changed'): void
    {
        MunicipalityInvestment::query()->active()->where('user_id', $investor->id)
            ->update(['ends_at' => now(), 'end_reason' => $reason, 'ended_by' => $actor?->id, 'updated_at' => now()]);
    }

    // ── Ativar / desativar (contrato desfeito) ──────────────────────────

    /**
     * Desativa o investidor: termina os períodos em vigor (o que já ganhou
     * fica no histórico), deixa de ver e exportar a faturação e os
     * municípios ficam livres para outro investidor. A conversa com o
     * Super Admin continua, para tratar do fecho do contrato.
     *
     * @return list<string> nomes dos municípios libertados
     */
    public function deactivate(User $actor, User $investor, ?string $reason): array
    {
        $this->assertGlobalSuper($actor);
        if ($investor->role !== UserRole::INVESTOR) {
            throw ValidationException::withMessages(['investor' => 'Esta conta não é de investidor.']);
        }
        if ($investor->investor_deactivated_at !== null) {
            throw ValidationException::withMessages(['investor' => 'Este investidor já está desativado.']);
        }

        $names = DB::transaction(function () use ($actor, $investor) {
            $rows = MunicipalityInvestment::query()->active()->where('user_id', $investor->id)->with('municipality:id,name')->lockForUpdate()->get();
            $this->endAll($investor, $actor, 'deactivated');
            $investor->forceFill(['investor_deactivated_at' => now()])->save();

            return $rows->map(fn ($r) => (string) $r->municipality?->name)->all();
        });

        AuditLog::create([
            'user_id' => $actor->id, 'action' => 'investor.deactivated', 'entity_type' => User::class, 'entity_id' => $investor->id,
            'new_values' => ['reason' => $reason ? \Illuminate\Support\Str::limit(trim($reason), 250, '') : null, 'municipalities' => $names],
        ]);
        $this->notify($investor->id, 'A sua conta de investidor foi desativada',
            'Deixa de receber a percentagem a partir de agora. O que já ganhou fica registado. Fale com o Super Admin pela conversa de investidor.' . ($reason ? ' Motivo: ' . trim($reason) : ''));

        return $names;
    }

    /**
     * Reativa: volta a abrir, a partir de AGORA, os municípios que terminaram
     * com a desativação e com a mesma percentagem — se entretanto não tiverem
     * outro investidor. Os ocupados ficam de fora (devolvidos em `skipped`).
     *
     * @return array{restored: list<string>, skipped: list<string>}
     */
    public function reactivate(User $actor, User $investor): array
    {
        $this->assertGlobalSuper($actor);
        if ($investor->investor_deactivated_at === null) {
            throw ValidationException::withMessages(['investor' => 'Este investidor já está ativo.']);
        }
        if ($investor->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['investor' => 'A conta não está ativa.']);
        }

        $result = DB::transaction(function () use ($actor, $investor) {
            $since = $investor->investor_deactivated_at;
            $ended = MunicipalityInvestment::query()->where('user_id', $investor->id)->where('end_reason', 'deactivated')
                ->where('ends_at', '>=', $since->copy()->subMinute())->with('municipality:id,name')->get();
            $restored = [];
            $skipped = [];
            foreach ($ended as $row) {
                $taken = MunicipalityInvestment::query()->active()->where('municipality_id', $row->municipality_id)->lockForUpdate()->exists();
                if ($taken) {
                    $skipped[] = (string) $row->municipality?->name;
                    continue;
                }
                MunicipalityInvestment::create([
                    'municipality_id' => $row->municipality_id, 'user_id' => $investor->id,
                    'percentage' => $row->percentage, 'starts_at' => now(), 'assigned_by' => $actor->id,
                ]);
                $restored[] = (string) $row->municipality?->name;
            }
            $investor->forceFill(['investor_deactivated_at' => null, 'role' => UserRole::INVESTOR])->save();

            return ['restored' => $restored, 'skipped' => $skipped];
        });

        AuditLog::create([
            'user_id' => $actor->id, 'action' => 'investor.reactivated', 'entity_type' => User::class, 'entity_id' => $investor->id,
            'new_values' => $result,
        ]);
        $this->notify($investor->id, 'A sua conta de investidor foi reativada',
            $result['restored'] ? 'Volta a receber a sua percentagem a partir de agora em: ' . implode(', ', $result['restored']) . '.' : 'A conta está ativa; o Super Admin vai associar-lhe os municípios.');

        return $result;
    }

    public function isDeactivated(User $investor): bool
    {
        return $investor->role === UserRole::INVESTOR && $investor->investor_deactivated_at !== null;
    }

    private function demoteIfIdle(User $investor): void
    {
        if ($investor->role === UserRole::INVESTOR && !MunicipalityInvestment::query()->active()->where('user_id', $investor->id)->exists()) {
            $investor->forceFill(['role' => UserRole::USER])->save();
        }
    }

    private function currentOrFail(User $investor, int $municipalityId): MunicipalityInvestment
    {
        $current = MunicipalityInvestment::query()->active()
            ->where('user_id', $investor->id)->where('municipality_id', $municipalityId)->lockForUpdate()->first();
        if (!$current) {
            throw new HttpException(404, 'Esta pessoa não é investidora deste município.');
        }

        return $current;
    }

    // ── O que o investidor vê ───────────────────────────────────────────

    /** Municípios em vigor do investidor (com a percentagem atual). */
    public function activeFor(User $investor): Collection
    {
        if ($investor->role !== UserRole::INVESTOR) {
            return collect();
        }

        return MunicipalityInvestment::query()->active()->where('user_id', $investor->id)
            ->with('municipality.province:id,name')->orderBy('starts_at')->get();
    }

    /**
     * Faturação para o investidor: um município (tem de estar em vigor em seu
     * nome) ou todos os seus (`null`). Só pedidos entregues dentro dos SEUS
     * períodos, no intervalo pedido (dias civis de Angola).
     */
    public function overview(User $investor, ?int $municipalityId, ?string $from, ?string $to): array
    {
        return $this->compute($investor, $municipalityId, $from, $to, false);
    }

    /**
     * Relatório para exportar (Excel/PDF) — exatamente os mesmos números do
     * painel, mais cada entrega (sem dados do cliente). Formato do
     * BillingReportService (title, subtitle, stats, sections, filename).
     */
    public function report(User $investor, ?int $municipalityId, ?string $from, ?string $to): array
    {
        $d = $this->compute($investor, $municipalityId, $from, $to, true);
        $kz = fn ($v) => number_format((float) $v, 2, ',', '.') . ' Kz';
        $pct = fn ($v) => $this->pct((float) $v);
        $dmy = fn (string $ymd) => Carbon::createFromFormat('Y-m-d', $ymd)->format('d/m/Y');
        $names = collect($d['municipalities'])->pluck('name', 'id');
        $scope = $municipalityId !== null ? ($names[$municipalityId] ?? 'Município') : 'Todos os seus municípios (' . count($d['municipalities']) . ')';

        $sections = [];
        if ($municipalityId === null && count($d['municipalities']) > 1) {
            $sections[] = [
                'title' => 'Por município',
                'headings' => ['Município', 'Percentagem atual', 'Entregas', 'Total arrecadado', 'Comissão KeruGás', 'A sua parte'],
                'rows' => collect($d['municipalities'])->map(function ($m) use ($d, $kz, $pct) {
                    $r = collect($d['by_municipality'])->firstWhere('municipality_id', $m['id']);

                    return [$m['name'], $pct($m['percentage']), (int) ($r['orders'] ?? 0), $kz($r['collected_total'] ?? 0), $kz($r['commission_total'] ?? 0), $kz($r['share_total'] ?? 0)];
                })->values()->all(),
                'totals' => ['Total', '', $d['summary']['orders'], $kz($d['summary']['collected_total']), $kz($d['summary']['commission_total']), $kz($d['summary']['share_total'])],
            ];
        }
        $sections[] = [
            'title' => 'Dia a dia',
            'headings' => ['Data', 'Entregas', 'Total arrecadado', 'Comissão KeruGás', 'A sua parte'],
            'rows' => array_map(fn ($r) => [$dmy($r['date']), $r['orders'], $kz($r['collected']), $kz($r['commission']), $kz($r['share'])], $d['daily']),
            'totals' => ['Total', $d['summary']['orders'], $kz($d['summary']['collected_total']), $kz($d['summary']['commission_total']), $kz($d['summary']['share_total'])],
        ];
        $sections[] = [
            'title' => 'Entregas',
            'headings' => ['Data e hora', 'Referência', 'Município', 'Garrafas', 'Total cobrado', 'Comissão', 'Percentagem', 'A sua parte'],
            'rows' => array_map(fn ($o) => [$o['at'], $o['reference'], $names[$o['municipality_id']] ?? '—', $o['quantity'], $kz($o['collected']), $o['commission'] === null ? 'por completar' : $kz($o['commission']), $pct($o['percentage']), $kz($o['share'])], $d['orders']),
            'totals' => ['Total', '', '', $d['summary']['bottles'], $kz($d['summary']['collected_total']), $kz($d['summary']['commission_total']), '', $kz($d['summary']['share_total'])],
            'note' => $d['summary']['pending_orders'] > 0 ? "{$d['summary']['pending_orders']} entrega(s) ainda sem a comissão separada pela central — entram no total, mas não na comissão nem na sua parte." : null,
        ];
        $sections[] = [
            'title' => 'Percentagens em vigor no período',
            'headings' => ['Município', 'Percentagem', 'Desde', 'Até'],
            'rows' => array_map(fn ($p) => [$names[$p['municipality_id']] ?? '—', $pct($p['percentage']), Carbon::parse($p['starts_at'])->setTimezone($this->timezone())->format('d/m/Y H:i'), $p['ends_at'] ? Carbon::parse($p['ends_at'])->setTimezone($this->timezone())->format('d/m/Y H:i') : 'em vigor'], $d['periods']->all()),
            'totals' => null,
            'note' => $d['rule'],
        ];

        $slug = $municipalityId !== null ? \Illuminate\Support\Str::slug((string) ($names[$municipalityId] ?? 'municipio')) : 'todos';

        return [
            'title' => 'Faturação do investidor',
            'subtitle' => "{$investor->name} · {$scope} · {$dmy($d['range']['from'])} a {$dmy($d['range']['to'])}",
            'stats' => [
                ['Entregas', (string) $d['summary']['orders']],
                ['Garrafas', (string) $d['summary']['bottles']],
                ['Total arrecadado', $kz($d['summary']['collected_total'])],
                ['Comissão KeruGás', $kz($d['summary']['commission_total'])],
                ['A sua parte', $kz($d['summary']['share_total'])],
            ],
            'sections' => $sections,
            'filename' => "kerugas-investidor-{$slug}-{$d['range']['from']}-a-{$d['range']['to']}",
        ];
    }

    private function compute(User $investor, ?int $municipalityId, ?string $from, ?string $to, bool $withOrders): array
    {
        if ($this->isDeactivated($investor)) {
            throw new HttpException(403, 'A conta de investidor está desativada.');
        }

        $active = $this->activeFor($investor);
        $allowed = $active->pluck('municipality_id')->map(fn ($id) => (int) $id)->all();

        if ($municipalityId !== null && !in_array($municipalityId, $allowed, true)) {
            throw new HttpException(403, 'Não é investidor deste município.');
        }

        [$fromAt, $toAt, $fromDay, $toDay] = $this->range($from, $to);
        $selected = $municipalityId !== null ? [$municipalityId] : $allowed;

        // Todos os períodos dele nesses municípios (incluindo percentagens antigas).
        $periods = MunicipalityInvestment::query()->where('user_id', $investor->id)
            ->whereIn('municipality_id', $selected ?: [0])
            ->where('starts_at', '<=', $toAt)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $fromAt))
            ->orderBy('starts_at')->get();

        $tz = $this->timezone();
        $summary = ['orders' => 0, 'bottles' => 0, 'collected_total' => 0.0, 'commission_total' => 0.0, 'share_total' => 0.0, 'pending_orders' => 0];
        $daily = [];
        $byMunicipality = [];
        $orderRows = [];

        foreach ($periods as $p) {
            $start = $p->starts_at->greaterThan($fromAt) ? $p->starts_at : $fromAt;
            $end = $p->ends_at && $p->ends_at->lessThan($toAt) ? $p->ends_at : $toAt;
            if ($start->greaterThanOrEqualTo($end)) {
                continue;
            }

            $orders = GasOrder::query()->where('status', GasOrderStatus::DELIVERED)
                ->where('municipality_id', $p->municipality_id)
                ->where('completed_at', '>=', $start)->where('completed_at', $p->ends_at && $end->equalTo($p->ends_at) ? '<' : '<=', $end)
                ->orderBy('completed_at')
                ->get(['id', 'order_reference', 'quantity', 'delivery_price', 'commission', 'completed_at']);

            $mid = (int) $p->municipality_id;
            $byMunicipality[$mid] ??= ['municipality_id' => $mid, 'orders' => 0, 'collected_total' => 0.0, 'commission_total' => 0.0, 'share_total' => 0.0];

            foreach ($orders as $o) {
                $collected = (float) $o->delivery_price;
                $commission = $o->commission !== null ? (float) $o->commission : 0.0;
                $share = round($commission * $p->percentage / 100, 2);
                $key = Carbon::parse($o->completed_at)->setTimezone($tz)->format('Y-m-d');

                $summary['orders']++;
                $summary['bottles'] += (int) $o->quantity;
                $summary['collected_total'] += $collected;
                $summary['commission_total'] += $commission;
                $summary['share_total'] += $share;
                if ($o->commission === null) {
                    $summary['pending_orders']++; // pedido antigo, ainda sem a divisão feita pela central
                }

                $daily[$key] ??= ['date' => $key, 'orders' => 0, 'collected' => 0.0, 'commission' => 0.0, 'share' => 0.0];
                $daily[$key]['orders']++;
                $daily[$key]['collected'] += $collected;
                $daily[$key]['commission'] += $commission;
                $daily[$key]['share'] += $share;

                $byMunicipality[$mid]['orders']++;
                $byMunicipality[$mid]['collected_total'] += $collected;
                $byMunicipality[$mid]['commission_total'] += $commission;
                $byMunicipality[$mid]['share_total'] += $share;

                if ($withOrders) {
                    $orderRows[] = [
                        'sort' => Carbon::parse($o->completed_at)->timestamp,
                        'at' => Carbon::parse($o->completed_at)->setTimezone($tz)->format('d/m/Y H:i'),
                        'reference' => $o->order_reference, 'municipality_id' => $mid, 'quantity' => (int) $o->quantity,
                        'collected' => $collected, 'commission' => $o->commission !== null ? (float) $o->commission : null,
                        'percentage' => $p->percentage, 'share' => $share,
                    ];
                }
            }
        }

        $round = fn (array $row) => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $row);
        ksort($daily);
        $names = Municipality::query()->whereIn('id', array_keys($byMunicipality))->pluck('name', 'id');

        usort($orderRows, fn ($a, $b) => $a['sort'] <=> $b['sort']);

        return [
            'orders' => $orderRows,
            'municipalities' => $active->map(fn (MunicipalityInvestment $a) => [
                'id' => (int) $a->municipality_id,
                'name' => $a->municipality?->name,
                'province' => $a->municipality?->province?->name,
                'percentage' => $a->percentage,
                'since' => $a->starts_at->toIso8601String(),
            ])->values(),
            'selected_municipality_id' => $municipalityId,
            'range' => ['from' => $fromDay, 'to' => $toDay],
            'summary' => $round($summary),
            'by_municipality' => collect($byMunicipality)->map(fn ($r) => $round($r + ['name' => $names[$r['municipality_id']] ?? null]))->values(),
            'daily' => array_values(array_map($round, $daily)),
            'periods' => $periods->map(fn (MunicipalityInvestment $p) => [
                'municipality_id' => (int) $p->municipality_id,
                'percentage' => $p->percentage,
                'starts_at' => $p->starts_at->toIso8601String(),
                'ends_at' => $p->ends_at?->toIso8601String(),
            ])->values(),
            'rule' => 'A sua parte é a sua percentagem sobre a comissão do KeruGás, só dos pedidos entregues desde que é investidor do município.',
        ];
    }

    // ── Auxiliares ──────────────────────────────────────────────────────

    /** @return array{0: CarbonInterface, 1: CarbonInterface, 2: string, 3: string} */
    private function range(?string $from, ?string $to): array
    {
        $tz = $this->timezone();
        $today = Carbon::now($tz);
        $f = $from ? Carbon::createFromFormat('Y-m-d', $from, $tz) : $today->copy()->startOfMonth();
        $t = $to ? Carbon::createFromFormat('Y-m-d', $to, $tz) : $today->copy();
        if (!$f || !$t) {
            throw ValidationException::withMessages(['from' => 'Datas inválidas.']);
        }
        $f = $f->startOfDay();
        $t = $t->endOfDay();
        if ($f->greaterThan($t)) {
            throw ValidationException::withMessages(['from' => 'A data inicial é depois da final.']);
        }
        if ($f->diffInDays($t) > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['from' => 'Escolha um intervalo de no máximo ' . self::MAX_RANGE_DAYS . ' dias.']);
        }

        $appTz = config('app.timezone');

        return [$f->copy()->setTimezone($appTz), $t->copy()->setTimezone($appTz), $f->format('Y-m-d'), $t->format('Y-m-d')];
    }

    private function startOf(?string $day): CarbonInterface
    {
        if (!$day) {
            return now();
        }
        $d = Carbon::createFromFormat('Y-m-d', $day, $this->timezone());
        if (!$d) {
            throw ValidationException::withMessages(['starts_on' => 'Data inválida.']);
        }

        return $d->startOfDay()->setTimezone(config('app.timezone'));
    }

    private function assertGlobalSuper(User $actor): void
    {
        if ($actor->role !== UserRole::SUPER_ADMIN) {
            throw new HttpException(403, 'Só o Super Admin geral gere investidores.');
        }
    }

    private function assertPercentage(float $percentage): void
    {
        if ($percentage <= 0 || $percentage > 100) {
            throw ValidationException::withMessages(['percentage' => 'A percentagem tem de estar entre 0,01 e 100.']);
        }
    }

    private function pct(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',') . '%';
    }

    private function day(CarbonInterface $at): string
    {
        return $at->copy()->setTimezone($this->timezone())->format('d/m/Y');
    }

    private function audit(User $actor, string $action, MunicipalityInvestment $row, array $extra): void
    {
        AuditLog::create([
            'user_id' => $actor->id, 'action' => $action,
            'entity_type' => MunicipalityInvestment::class, 'entity_id' => $row->id,
            'new_values' => ['investor_id' => $row->user_id, 'municipality_id' => $row->municipality_id] + $extra,
        ]);
    }

    private function notify(int $userId, string $title, string $body): void
    {
        try {
            AppNotification::create([
                'user_id' => $userId, 'category' => NotificationCategory::SYSTEM,
                'title' => $title, 'body' => $body, 'data' => ['type' => 'investor', 'path' => '/investidor'],
            ]);
        } catch (Throwable) {
            // melhor esforço
        }
    }
}
