<?php

namespace App\Services\Sms;

use App\Enums\OperationStatus;
use App\Enums\StationStatus;
use App\Models\Municipality;
use App\Models\Operation;
use App\Models\SmsTemplate;
use App\Models\Station;
use App\Models\User;
use App\Services\FreshnessService;
use App\Services\PhoneNumberService;
use Illuminate\Support\Collection;

/**
 * Orquestra o fluxo manual da Central de Operações: um admin/super
 * admin lê um SMS no telefone físico da Central e inicia aqui um
 * atendimento (Operation), que consulta os postos do município e
 * prepara a resposta a enviar.
 *
 * Este serviço NUNCA é acionado automaticamente a partir de um SMS
 * recebido — é sempre o admin quem inicia o fluxo pela interface.
 */
class OperationService
{
    public function __construct(
        private readonly PhoneNumberService $phoneNumberService,
        private readonly FreshnessService $freshnessService,
    ) {
    }

    /**
     * Cria o registo de atendimento (rascunho). O `original_message`,
     * quando fornecido, é sempre texto introduzido manualmente pelo
     * admin e é guardado apenas como referência operacional.
     */
    public function startDraft(User $admin, string $rawPhone, ?int $provinceId, ?int $municipalityId, ?string $originalMessage): Operation
    {
        $normalizedPhone = $this->phoneNumberService->normalize($rawPhone);

        return Operation::create([
            'admin_id' => $admin->id,
            'phone' => $normalizedPhone ?? $rawPhone,
            'province_id' => $provinceId,
            'municipality_id' => $municipalityId,
            'original_message' => $originalMessage,
            'status' => OperationStatus::DRAFT,
        ]);
    }

    /**
     * Consulta os postos ativos do município, com estado de gás, fila
     * e frescura da última atualização — para o admin decidir o
     * template e confirmar antes do envio.
     */
    public function stationsForMunicipality(Municipality $municipality): Collection
    {
        return Station::query()
            ->where('municipality_id', $municipality->id)
            ->where('status', StationStatus::ACTIVE)
            ->orderByDesc('current_gas_available')
            ->orderBy('current_queue_level')
            ->get()
            ->map(fn (Station $station) => [
                'id' => $station->id,
                'name' => $station->name,
                'slug' => $station->slug,
                'reference_point' => $station->reference_point,
                'gas_available' => (bool) $station->current_gas_available,
                'queue_level' => $station->current_queue_level?->value,
                'queue_level_label' => $station->current_queue_level?->label(),
                'status_updated_at' => $station->status_updated_at?->toIso8601String(),
                'freshness' => $this->freshnessService->level($station->status_updated_at)->value,
            ]);
    }

    /**
     * Constrói o contexto de variáveis do template a partir dos dados
     * do atendimento e da consulta de postos, respeitando a whitelist
     * de SmsTemplateRenderer.
     *
     * @param Collection<int, array> $stationsWithGas Postos já filtrados com gás disponível
     */
    public function buildTemplateContext(Operation $operation, Collection $stationsWithGas): array
    {
        $now = now();

        return [
            'telefone' => $operation->phone,
            'provincia' => $operation->province?->name ?? '',
            'municipio' => $operation->municipality?->name ?? '',
            'quantidade_postos' => (string) $stationsWithGas->count(),
            'postos' => $this->formatStationsList($stationsWithGas),
            'data' => $now->format('d/m/Y'),
            'hora' => $now->format('H:i'),
        ];
    }

    /**
     * LIMITE DE SMS (Setembro 2026): a Kamba (e SMS em geral) recusa
     * mensagens acima de 160 caracteres — `{{postos}}` é a única parte
     * verdadeiramente sem limite do template AVAILABILITY (o resto é
     * texto fixo curto), por isso lista no máximo 2 postos por SMS,
     * com uma nota de quantos mais ficaram de fora. Sem este limite,
     * um município com muitos postos com gás voltaria a produzir
     * mensagens acima de 160 caracteres, tal como os templates
     * antigos (193-234 caracteres, já corrigidos).
     */
    private const MAX_STATIONS_IN_SMS = 2;

    private function formatStationsList(Collection $stations): string
    {
        if ($stations->isEmpty()) {
            return '';
        }

        $shown = $stations->take(self::MAX_STATIONS_IN_SMS);
        $remaining = $stations->count() - $shown->count();

        $lines = $shown
            ->map(fn (array $station) => sprintf('- %s (%s)', $station['name'], $station['queue_level_label'] ?? 'fila desconhecida'))
            ->implode("\n");

        if ($remaining > 0) {
            $lines .= "\n+{$remaining} noutros postos.";
        }

        return $lines;
    }

    public function cancel(Operation $operation): Operation
    {
        $operation->update(['status' => OperationStatus::CANCELLED]);

        return $operation->fresh();
    }

    public function attachTemplate(Operation $operation, SmsTemplate $template): Operation
    {
        $operation->update(['sms_template_id' => $template->id]);

        return $operation->fresh();
    }

    public function markSent(Operation $operation): Operation
    {
        $operation->update(['status' => OperationStatus::SENT]);

        return $operation->fresh();
    }
}
