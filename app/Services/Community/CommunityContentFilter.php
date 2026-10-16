<?php

namespace App\Services\Community;

use App\Models\CommunityBannedWord;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Regras de conteúdo dos Vizinhos KeruGás (Outubro 2026):
 *  - telemóveis e ligações estão BLOQUEADOS para utilizadores comuns (evita
 *    burlas e spam; a equipa — Admin para cima, e o Moderador — fica isenta);
 *  - palavras proibidas (lista gerida pelo Super Admin) valem para todos.
 * Devolve uma frase amável em português, ou null se estiver tudo bem.
 */
class CommunityContentFilter
{
    /** 9 dígitos a começar por 9 (telemóvel angolano), com + 244 e separadores simples. */
    private const PHONE = '/(?<![\d])(?:\+?\s?244[\s.\-]?)?9(?:[\s.\-]?\d){8}(?![\d])/u';

    private const LINK = '/(?:https?:\/\/|www\.|\b(?:wa\.me|t\.me|bit\.ly|tinyurl\.com)\b|\b[a-z0-9][a-z0-9\-]*\.(?:com|net|org|ao|co|io|me|app|link|ly|gl|xyz|info|biz|tv|cc|gg|site|online|store|shop|top|click)\b)/iu';

    public function check(User $author, string $body): ?string
    {
        // Menções (@[12]) não contam como números.
        $text = preg_replace('/@\[\d+\]/', '@', $body) ?? $body;

        if (!CommunityAccess::isStaff($author)) {
            if (preg_match(self::PHONE, $text)) {
                return 'Por segurança, não é possível partilhar números de telefone aqui. Para falar com alguém, mencione-o com @.';
            }
            if (preg_match(self::LINK, $text)) {
                return 'Por segurança, não é possível partilhar ligações (links) aqui.';
            }
        }

        $normalized = ' ' . preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($text))) . ' ';
        foreach (CommunityBannedWord::query()->pluck('word') as $word) {
            $needle = trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii((string) $word))));
            if ($needle !== '' && str_contains($normalized, ' ' . $needle . ' ')) {
                return 'A mensagem tem palavras que não são permitidas na comunidade. Reescreva com respeito, por favor.';
            }
        }

        return null;
    }
}
