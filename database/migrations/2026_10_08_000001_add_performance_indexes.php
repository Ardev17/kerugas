<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Velocidade (Outubro 2026): índices para as listas que o site e o app
 * pedem a toda a hora (muitas de 20 em 20 ou 30 em 30 segundos).
 *
 * Todas ordenam por `created_at` ("mais recentes primeiro") depois de
 * filtrar por pessoa/condutor/estado/município. Sem um índice que acabe em
 * `created_at`, a base de dados lia TODAS as linhas dessa pessoa e ordenava
 * em memória a cada pedido — rápido com 50 pedidos, lento com 50 000.
 *
 * Só acrescenta índices (nenhum dado muda) e salta os que já existirem.
 */
return new class extends Migration
{
    /** @var array<string, list<list<string>>> */
    private array $indexes = [
        'gas_orders' => [
            ['user_id', 'created_at'],                    // "Os meus pedidos" e pedido ativo
            ['assigned_to', 'status', 'created_at'],      // pedidos do condutor (polling 30 s)
            ['status', 'created_at'],                     // lista de pedidos da central (polling 20 s)
            ['province_id', 'status', 'created_at'],      // central de uma província
            ['created_at'],                               // contagens "hoje" do painel
        ],
        'gas_order_events' => [
            ['gas_order_id', 'to_status', 'created_at'],  // tempos de entrega do condutor
        ],
        'notifications' => [
            ['user_id', 'created_at'],                    // sininho (polling 30 s)
        ],
        'chat_messages' => [
            ['municipality_id', 'created_at'],            // chat do condutor (só filtra por município)
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $list) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            foreach ($list as $columns) {
                $name = $this->name($table, $columns);
                if (Schema::hasIndex($table, $name) || Schema::hasIndex($table, $columns)) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $list) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            foreach ($list as $columns) {
                $name = $this->name($table, $columns);
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }

    /** @param list<string> $columns */
    private function name(string $table, array $columns): string
    {
        return 'perf_' . substr(md5($table . ':' . implode(',', $columns)), 0, 12);
    }
};
