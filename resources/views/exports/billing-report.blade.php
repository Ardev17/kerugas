<!DOCTYPE html>
<html lang="pt-AO">
<head>
<meta charset="utf-8">
<title>KeruGás — {{ $report['title'] }}</title>
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

    h2.section { font-size: 11px; color: #0B5CFF; margin: 18px 0 6px 0; }
    table.data tfoot td { font-weight: bold; border-top: 2px solid #0B5CFF; padding: 6px; font-size: 9px; }
    .note { font-size: 8.5px; color: #B45309; margin-top: 4px; }
    .stat-card .value.small { font-size: 12px; }

    .empty-state {
        text-align: center;
        padding: 30px;
        color: #94A3B8;
        font-size: 10px;
    }

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
                    <div class="brand-tagline">Faturação — KeruGas</div>
                </td>
                <td class="meta-cell">
                    <div><strong>{{ $report['subtitle'] }}</strong></div>
                    <div>Gerado em {{ $generatedAt }}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="page-footer">
        © {{ date('Y') }} KeruGás — Faturação · Página <span class="page-number"></span>
    </div>

    <div class="doc-title">{{ $report['title'] }}</div>

    <table class="stats-row">
        <tr>
            @foreach ($report['stats'] as $i => $stat)
                <td>
                    <div class="stat-card {{ $i === 0 ? 'accent' : '' }}">
                        <div class="label">{{ $stat[0] }}</div>
                        <div class="value small">{{ $stat[1] }}</div>
                    </div>
                </td>
            @endforeach
        </tr>
    </table>

    @foreach ($report['sections'] as $section)
        <h2 class="section">{{ $section['title'] }}</h2>
        <table class="data">
            <thead>
                <tr>
                    @foreach ($section['headings'] as $heading)
                        <th>{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($section['rows'] as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($section['headings']) }}" class="empty-state">Sem registos.</td></tr>
                @endforelse
            </tbody>
            @if (!empty($section['totals']) && !empty($section['rows']))
                <tfoot>
                    <tr>
                        @foreach ($section['totals'] as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
        @if (!empty($section['note']))
            <div class="note">{{ $section['note'] }}</div>
        @endif
    @endforeach

</body>
</html>
