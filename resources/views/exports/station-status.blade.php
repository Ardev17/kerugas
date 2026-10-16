<!DOCTYPE html>
<html lang="pt-AO">
<head>
<meta charset="utf-8">
<title>KeruGás — Histórico de atualizações</title>
<style>
    @page {
        margin: 90px 32px 60px 32px;
    }

    * { box-sizing: border-box; }

    body {
        font-family: Helvetica, Arial, sans-serif;
        font-size: 10px;
        color: #0F172A;
        margin: 0;
    }

    /* Cabeçalho repetido em todas as páginas (dompdf: elemento com
       position:fixed dentro do fluxo normal é fixado no topo de cada
       página quando colocado antes do conteúdo). */
    .page-header {
        position: fixed;
        top: -78px;
        left: 0;
        right: 0;
        height: 70px;
        border-bottom: 2px solid #0B5CFF;
        padding-bottom: 10px;
    }

    .page-header table { width: 100%; border-collapse: collapse; }
    .page-header td { border: none; padding: 0; }

    .logo-cell { width: 56px; vertical-align: middle; }
    .logo-cell img { width: 48px; height: 48px; }

    .brand-cell { vertical-align: middle; padding-left: 12px; }
    .brand-name { font-size: 20px; font-weight: bold; color: #0B5CFF; letter-spacing: 0.3px; }
    .brand-tagline { font-size: 9px; color: #64748B; margin-top: 1px; }

    .meta-cell { vertical-align: middle; text-align: right; font-size: 9px; color: #475569; line-height: 1.5; }
    .meta-cell strong { color: #0F172A; }

    .doc-title {
        font-size: 14px;
        font-weight: bold;
        color: #0F172A;
        margin: 4px 0 14px 0;
    }
    .doc-title span { color: #0B5CFF; }

    /* Cartões de resumo */
    .stats-row { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin-bottom: 16px; }
    .stats-row td { padding: 0; }
    .stat-card {
        border: 1px solid #E2E8F0;
        border-radius: 6px;
        padding: 9px 12px;
        background: #F8FAFC;
    }
    .stat-card .label { font-size: 8px; text-transform: uppercase; letter-spacing: 0.4px; color: #64748B; }
    .stat-card .value { font-size: 16px; font-weight: bold; color: #0F172A; margin-top: 2px; }
    .stat-card.accent { background: #EFF6FF; border-color: #BFDBFE; }
    .stat-card.accent .value { color: #0B5CFF; }
    .stat-card.success .value { color: #16A34A; }
    .stat-card.danger .value { color: #DC2626; }

    /* Tabela de dados */
    table.data { width: 100%; border-collapse: collapse; }
    table.data thead th {
        background: #0B5CFF;
        color: #ffffff;
        text-align: left;
        padding: 7px 6px;
        font-size: 8.5px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    table.data thead th:first-child { border-top-left-radius: 4px; }
    table.data thead th:last-child { border-top-right-radius: 4px; }
    table.data tbody td {
        border-bottom: 1px solid #E2E8F0;
        padding: 6px;
        font-size: 9px;
    }
    table.data tbody tr:nth-child(even) td { background: #F8FAFC; }

    .badge {
        display: inline-block;
        padding: 1px 7px;
        border-radius: 8px;
        font-size: 8.5px;
        font-weight: bold;
    }
    .badge-yes { background: #DCFCE7; color: #15803D; }
    .badge-no { background: #FEE2E2; color: #B91C1C; }

    .empty-state {
        text-align: center;
        padding: 30px;
        color: #94A3B8;
        font-size: 10px;
    }

    /* Rodapé repetido em todas as páginas */
    .page-footer {
        position: fixed;
        bottom: -48px;
        left: 0;
        right: 0;
        height: 40px;
        border-top: 1px solid #E2E8F0;
        padding-top: 8px;
        text-align: center;
        color: #94A3B8;
        font-size: 8px;
    }
    .page-footer .page-number:after {
        content: counter(page) " / " counter(pages);
    }
</style>
</head>
<body>

    {{-- position:fixed repete este bloco em todas as páginas do PDF
         (suportado nativamente pelo dompdf — não precisa de tag especial). --}}
    <div class="page-header">
        <table>
            <tr>
                <td class="logo-cell">
                    @if ($logoDataUri)
                        <img src="{{ $logoDataUri }}" alt="KeruGás">
                    @endif
                </td>
                <td class="brand-cell">
                    <div class="brand-name">KeruGás</div>
                    <div class="brand-tagline">Encontre gás perto de si</div>
                </td>
                <td class="meta-cell">
                    <div><strong>{{ $periodLabel }}</strong></div>
                    <div>Gerado em {{ $generatedAt }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="page-footer">
        © {{ date('Y') }} KeruGás — Central de Operações · Página <span class="page-number"></span>
    </div>

    <div class="doc-title">Histórico de <span>atualizações de estado</span></div>

    <table class="stats-row">
        <tr>
            <td style="width: 22%;">
                <div class="stat-card accent">
                    <div class="label">Total de registos</div>
                    <div class="value">{{ $stats['total'] }}</div>
                </div>
            </td>
            <td style="width: 22%;">
                <div class="stat-card success">
                    <div class="label">Com gás</div>
                    <div class="value">{{ $stats['com_gas'] }}</div>
                </div>
            </td>
            <td style="width: 22%;">
                <div class="stat-card danger">
                    <div class="label">Sem gás</div>
                    <div class="value">{{ $stats['sem_gas'] }}</div>
                </div>
            </td>
            <td style="width: 22%;">
                <div class="stat-card">
                    <div class="label">Postos únicos</div>
                    <div class="value">{{ $stats['postos_unicos'] }}</div>
                </div>
            </td>
            <td style="width: 12%;">
                <div class="stat-card accent">
                    <div class="label">% Com gás</div>
                    <div class="value">{{ $stats['percentagem_com_gas'] }}%</div>
                </div>
            </td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th>Data/Hora</th>
                <th>Posto</th>
                <th>Município</th>
                <th>Província</th>
                <th>Gás</th>
                <th>Fila</th>
                <th>Confiança</th>
                <th>Origem</th>
                <th>Atualizado por</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['data_hora'] }}</td>
                    <td>{{ $row['posto'] }}</td>
                    <td>{{ $row['municipio'] }}</td>
                    <td>{{ $row['provincia'] }}</td>
                    <td>
                        <span class="badge {{ $row['gas_disponivel'] === 'Sim' ? 'badge-yes' : 'badge-no' }}">
                            {{ $row['gas_disponivel'] }}
                        </span>
                    </td>
                    <td>{{ $row['fila'] }}</td>
                    <td>{{ $row['confianca'] }}</td>
                    <td>{{ $row['origem'] }}</td>
                    <td>{{ $row['atualizado_por'] }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="empty-state">Sem registos neste período/filtro.</td></tr>
            @endforelse
        </tbody>
    </table>

</body>
</html>
