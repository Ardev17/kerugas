<?php

namespace App\Services\Sms;

use App\Enums\SmsMessageStatus;
use App\Enums\StationUpdateSource;
use App\Models\SmsMessage;
use App\Models\Station;
use App\Services\PhoneNumberService;
use App\Services\StationStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Motor central de processamento de SMS recebidos dos POSTOS.
 *
 * IMPORTANTE (revisão da Central de Operações KeruGás, 2026):
 * Este serviço processa exclusivamente o código de 2 dígitos que os
 * POSTOS enviam para autoatualizar o próprio estado (gás/fila) por SMS.
 * Ele NÃO responde a clientes/consumidores.
 *
 * A consulta de disponibilidade que um cliente pede por SMS ao número
 * físico da Central é, por decisão de produto, um fluxo 100% manual:
 * o operador lê o SMS no telefone físico e inicia o atendimento na
 * Central de Operações (ver App\Services\Sms\OperationService). Nunca
 * deve existir aqui um caminho que responda automaticamente a um
 * cliente com base num SMS recebido — isso seria receção automática de
 * SMS de clientes, o que está explicitamente fora do âmbito desta fase.
 *
 * Fluxo (Master Build Prompt, secção 26):
 *   Webhook -> validação de assinatura -> normalização de telefone
 *   -> identificação do posto -> validação do comando
 *   -> processamento transacional -> evento -> notificação -> audit log.
 *
 * Garante idempotência: SMS com o mesmo provider_message_id nunca é
 * processado duas vezes (REGRA 3 do master prompt).
 */
class SmsProcessingService
{
    public function __construct(
        private readonly PhoneNumberService $phoneNumberService,
        private readonly SmsCommandParser $commandParser,
        private readonly StationStatusService $stationStatusService,
    ) {
    }

    public function process(array $normalizedPayload): SmsMessage
    {
        $providerMessageId = $normalizedPayload['provider_message_id'] ?? null;

        // Idempotência: se já processámos este message_id, devolver o registo existente.
        if ($providerMessageId) {
            $existing = SmsMessage::where('provider_message_id', $providerMessageId)->first();
            if ($existing) {
                return $existing;
            }
        }

        $sender = $this->phoneNumberService->normalize($normalizedPayload['sender']) ?? $normalizedPayload['sender'];

        $smsMessage = SmsMessage::create([
            'provider_message_id' => $providerMessageId,
            'sender' => $sender,
            'recipient' => $normalizedPayload['recipient'] ?? null,
            'body' => $normalizedPayload['body'],
            'status' => SmsMessageStatus::RECEIVED,
            'received_at' => $normalizedPayload['received_at'],
        ]);

        try {
            // 1. É um posto autorizado a enviar código de status?
            $station = Station::where('sms_phone', $sender)->first();

            if ($station) {
                $this->processStationUpdate($smsMessage, $station);

                return $smsMessage->fresh();
            }

            // Qualquer outro remetente (incluindo consultas "GAS" de
            // clientes) não é processado automaticamente. O atendimento
            // a clientes é sempre manual, via Central de Operações.
            $smsMessage->update([
                'status' => SmsMessageStatus::INVALID,
                'processed_at' => now(),
                'error' => 'Remetente não é um posto autorizado. Atendimentos a clientes são processados manualmente na Central de Operações.',
            ]);
        } catch (\Throwable $e) {
            Log::channel('single')->error('[SmsProcessingService] erro ao processar SMS', [
                'sms_message_id' => $smsMessage->id,
                'error' => $e->getMessage(),
            ]);

            $smsMessage->update([
                'status' => SmsMessageStatus::FAILED,
                'processed_at' => now(),
                'error' => $e->getMessage(),
            ]);
        }

        return $smsMessage->fresh();
    }

    private function processStationUpdate(SmsMessage $smsMessage, Station $station): void
    {
        $parsed = $this->commandParser->parseStationCode($smsMessage->body);

        if ($parsed === null) {
            $smsMessage->update([
                'status' => SmsMessageStatus::INVALID,
                'station_id' => $station->id,
                'processed_at' => now(),
                'error' => 'Código de status inválido. Esperado formato de 2 dígitos (ex: 10).',
            ]);

            return;
        }

        DB::transaction(function () use ($smsMessage, $station, $parsed) {
            $this->stationStatusService->applyUpdate(
                $station,
                $parsed['gas_available'],
                $parsed['queue_level'],
                StationUpdateSource::OFFICIAL_SMS,
            );

            $smsMessage->update([
                'status' => SmsMessageStatus::PROCESSED,
                'station_id' => $station->id,
                'parsed_command' => $smsMessage->body,
                'processed_at' => now(),
            ]);
        });
    }
}
