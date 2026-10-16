<?php

namespace App\Services\Push;

enum PushResult: string
{
    case SENT = 'sent';
    /** O token já não é válido (app desinstalado, dados limpos) — apagar. */
    case INVALID_TOKEN = 'invalid_token';
    /** Falha temporária (rede, quota, Google em baixo) — manter o token. */
    case FAILED = 'failed';
}
