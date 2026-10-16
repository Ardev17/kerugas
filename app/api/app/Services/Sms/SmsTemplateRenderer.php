<?php

namespace App\Services\Sms;

/**
 * Renderiza o conteúdo de um template SMS substituindo variáveis
 * {{variavel}} por valores concretos, usando exclusivamente uma
 * whitelist fixa (secção 17 do master prompt). Nunca executa código
 * arbitrário — apenas substituição de texto simples.
 */
class SmsTemplateRenderer
{
    public const ALLOWED_VARIABLES = [
        'cliente', 'telefone', 'provincia', 'municipio', 'quantidade_postos',
        'postos', 'data', 'hora', 'posto', 'localizacao', 'disponibilidade', 'fila',
    ];

    public function render(string $content, array $context): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/',
            function (array $matches) use ($context): string {
                $key = $matches[1];

                if (!in_array($key, self::ALLOWED_VARIABLES, true)) {
                    // Nunca deveria acontecer se o template foi validado ao
                    // ser criado/editado — por segurança, não substitui.
                    return $matches[0];
                }

                return (string) ($context[$key] ?? '');
            },
            $content
        );
    }

    /**
     * @return string[] Nomes das variáveis usadas no conteúdo (sem chavetas).
     */
    public function extractVariables(string $content): array
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $content, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return string[] Erros de validação encontrados; vazio se válido.
     */
    public function validate(string $content): array
    {
        $errors = [];

        if (trim($content) === '') {
            $errors[] = 'O conteúdo do template não pode estar vazio.';

            return $errors;
        }

        $unknown = array_diff($this->extractVariables($content), self::ALLOWED_VARIABLES);

        if ($unknown !== []) {
            $errors[] = 'Variáveis desconhecidas no template: {{' . implode('}}, {{', $unknown) . '}}.';
        }

        return $errors;
    }
}
