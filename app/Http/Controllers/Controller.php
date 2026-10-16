<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Controller base.
 *
 * BUG CORRIGIDO (Setembro 2026, varredura antes do app Android): no
 * Laravel 11 o esqueleto oficial traz este ficheiro VAZIO — já não inclui
 * o trait `AuthorizesRequests` que o Laravel 10 trazia. Mas
 * GasOrderController chama `$this->authorize('view', $order)` (e outros
 * controllers podem vir a fazê-lo), o que rebentava em runtime com
 * "Call to undefined method ...::authorize()". Com o trait aqui, todos
 * os controllers voltam a ter `authorize()`, exatamente como a
 * documentação do Laravel 11 recomenda.
 *
 * Também incluído explicitamente em `apps/api` para a árvore ser
 * autossuficiente — substitua o `app/Http/Controllers/Controller.php`
 * do seu projeto por este.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
