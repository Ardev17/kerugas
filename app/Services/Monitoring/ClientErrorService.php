<?php

namespace App\Services\Monitoring;

use App\Models\ClientError;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Relatórios de erros do site e do app (Outubro 2026).
 *
 * - Agrupa por fingerprint (tipo + mensagem normalizada + 1.ª linha da
 *   pilha): 10 000 ocorrências do mesmo erro = 1 linha com contagem.
 * - Limpa dados pessoais antes de guardar: tokens, emails, números de
 *   telefone e query strings saem da mensagem, da pilha e do URL.
 * - Teto de MAX_ROWS erros diferentes: um atacante não enche a base de dados.
 * - Um erro "resolvido" que volta a acontecer reabre sozinho.
 */
class ClientErrorService
{
    public const MAX_ROWS = 5000;

    public const RETENTION_DAYS = 90;

    /** @param array{source:string, name?:?string, message:string, stack?:?string, url?:?string, app_version?:?string} $data */
    public function record(array $data, ?int $userId, ?string $userAgent): void
    {
        $message = self::scrub($data['message'], 500);
        $name = isset($data['name']) ? self::scrub((string) $data['name'], 120) : null;
        $stack = isset($data['stack']) ? self::scrub((string) $data['stack'], 4000) : null;
        $path = self::path($data['url'] ?? null);
        $source = $data['source'];

        $fingerprint = sha1(implode('|', [
            $source,
            (string) $name,
            preg_replace('/\d+/', '#', $message),
            self::firstFrame($stack),
        ]));

        $now = now();
        $existing = ClientError::where('fingerprint', $fingerprint)->first();

        if ($existing) {
            $update = ['occurrences' => DB::raw('occurrences + 1'), 'last_seen_at' => $now, 'updated_at' => $now];
            // Só um relatório de alguém com sessão reabre um erro resolvido ou
            // muda os detalhes — um anónimo não consegue "falsificar" o estado.
            if ($userId !== null) {
                $update += [
                    'last_user_id' => $userId,
                    'app_version' => $data['app_version'] ?? $existing->app_version,
                    'url_path' => $path ?? $existing->url_path,
                    'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : $existing->user_agent,
                    'resolved_at' => null,
                ];
            }
            ClientError::whereKey($existing->id)->update($update);

            return;
        }

        if (ClientError::count() >= self::MAX_ROWS) {
            // Cheio: sai o erro mais antigo que só aconteceu uma vez (lixo ou
            // abuso), para um erro novo e real nunca ficar de fora.
            $evict = ClientError::where('occurrences', 1)->orderBy('last_seen_at')->value('id');
            if ($evict === null) {
                return;
            }
            ClientError::whereKey($evict)->delete();
        }

        try {
            ClientError::create([
                'fingerprint' => $fingerprint,
                'source' => $source,
                'name' => $name,
                'message' => $message,
                'stack' => $stack,
                'url_path' => $path,
                'app_version' => $data['app_version'] ?? null,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                'last_user_id' => $userId,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);
        } catch (QueryException) {
            // Corrida: outro pedido criou o mesmo erro ao mesmo tempo — conta-o.
            ClientError::where('fingerprint', $fingerprint)->increment('occurrences', 1, ['last_seen_at' => $now]);
        }
    }

    /**
     * Primeira linha da pilha que é mesmo um "frame" (Chrome: "    at f (url:1:2)";
     * Firefox/Safari: "f@url:1:2"), sem linha/coluna e sem o hash do ficheiro
     * do build — o mesmo erro mantém o mesmo grupo entre versões publicadas.
     */
    public static function firstFrame(?string $stack): string
    {
        if ($stack === null) {
            return '';
        }

        foreach (preg_split('/\R/', $stack) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/^at\s|@/', $line)) {
                $line = preg_replace('/[?#][^)\s]*|:\d+:\d+\)?$|:\d+\)?$/', '', $line) ?? $line;

                return preg_replace('/-[A-Za-z0-9_]{6,}\.js/', '.js', $line) ?? $line;
            }
        }

        return '';
    }

    /** Remove dados pessoais/segredos e corta o tamanho. */
    public static function scrub(string $text, int $max): string
    {
        $text = preg_replace([
            '/Bearer\s+[A-Za-z0-9|._\-]+/i',
            '/\b\d+\|[A-Za-z0-9]{20,}\b/',              // tokens do Sanctum
            '/([?&](?:token|password|pwd|code|key|signature)=)[^&\s#]+/i',
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',  // emails
            '/\+?\d[\d\s\-]{7,}\d/',                     // telefones e outros números longos
        ], ['Bearer [oculto]', '[token]', '$1[oculto]', '[email]', '[número]'], $text) ?? '';

        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';

        return mb_substr(trim($text), 0, $max);
    }

    /** Só o caminho (sem domínio nem query string), com números longos ocultos. */
    public static function path(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, '/')) {
            return null;
        }

        return mb_substr(self::scrub($path, 255), 0, 255);
    }

    public function prune(): int
    {
        return ClientError::where('last_seen_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();
    }
}
