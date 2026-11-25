<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Lançada por SmsDispatchService::dispatch() quando o Admin autor do
 * pedido já atingiu o seu limite diário de SMS (secção de auditoria e
 * controlo de custos, Setembro 2026). Capturada em
 * OperationController::send() para devolver 429 com uma mensagem clara,
 * em vez de deixar o erro genérico chegar ao frontend.
 */
class SmsDailyLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $limit,
        public readonly int $sentToday,
    ) {
        parent::__construct("Limite diário de {$limit} SMS atingido ({$sentToday} já enviados hoje).");
    }
}
