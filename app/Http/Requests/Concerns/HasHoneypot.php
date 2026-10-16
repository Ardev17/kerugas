<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Log;

/**
 * Honeypot anti-bot (Setembro 2026 no contacto; Outubro 2026 alargado ao
 * registo, à recuperação de senha e ao pedido de gás sem conta).
 *
 * O formulário tem um campo `website` escondido (fora do ecrã, fora do
 * Tab, invisível para leitores de ecrã). Uma pessoa nunca o preenche;
 * um bot que preenche todos os inputs, sim. Zero atrito para quem usa a
 * plataforma — ao contrário de um captcha — e sem serviços externos.
 *
 * O campo nunca é obrigatório: versões antigas do app que não o enviam
 * continuam a funcionar. Só é recusado quando vem PREENCHIDO.
 */
trait HasHoneypot
{
    public static function honeypotField(): string
    {
        return 'website';
    }

    /** Verdadeiro quando o pedido caiu na armadilha. */
    public function hitHoneypot(): bool
    {
        return filled($this->input(self::honeypotField()));
    }

    /** @return array<string, array<int, string>> */
    protected function honeypotRules(): array
    {
        return [self::honeypotField() => ['prohibited']];
    }

    /** @return array<string, string> */
    protected function honeypotMessages(): array
    {
        // Mensagem genérica de propósito — nunca revelar a um bot que o
        // campo é uma armadilha.
        return [self::honeypotField() . '.prohibited' => 'Não foi possível validar o formulário.'];
    }

    /**
     * O campo-armadilha nunca chega aos dados validados (nem vazio) —
     * assim nunca vai parar a um `Model::create($data)`.
     */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated();
        unset($data[self::honeypotField()]);

        return data_get($data, $key, $default);
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($validator->errors()->has(self::honeypotField())) {
            self::logHoneypotHit($this);
        }

        parent::failedValidation($validator);
    }

    /** Regista a tentativa (sem dados do formulário — só onde e de que IP). */
    public static function logHoneypotHit(\Illuminate\Http\Request $request): void
    {
        Log::notice('[KERUGAS] Bot travado pelo honeypot', [
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
