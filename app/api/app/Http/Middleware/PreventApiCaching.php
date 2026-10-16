<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BUG CORRIGIDO: sem cabeçalhos explícitos a proibir cache, o browser
 * podia guardar em cache a resposta de um GET à API (ex:
 * /operator/station) e voltar a servi-la para pedidos idênticos
 * seguintes — mesmo depois de o React Query invalidar e voltar a
 * pedir os dados. Resultado visível: uma atualização de estado feita
 * pelo operador ficava corretamente guardada na base de dados (o
 * histórico provava isso), mas o ecrã continuava a mostrar o
 * primeiro valor alguma vez recebido para aquele endpoint, porque o
 * pedido nunca chegava mesmo a sair para a rede.
 *
 * Aplicado a toda a API (grupo `api` em bootstrap/app.php) — respostas
 * da API nunca devem ser guardadas em cache pelo browser; quem quiser
 * cache do lado do cliente fá-lo explicitamente (React Query já trata
 * disso na memória, de forma controlada e invalidável).
 */
class PreventApiCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
