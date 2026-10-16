<?php

namespace App\Http\Controllers\Api\V1\Seo;

use App\Http\Controllers\Controller;
use App\Models\Station;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * SEO (Outubro 2026): sitemap dos postos — /sitemap-postos.xml no domínio
 * (o nginx encaminha para aqui). Só postos ATIVOS e não apagados; cada um
 * aponta para a página pública /postos/{id}-{nome}. O resto do sitemap (índice e
 * páginas fixas), o robots.txt e o llms.txt são gerados no build do site
 * (apps/web/plugins/seoPrerender.ts).
 *
 * Em cache 1 hora: com milhares de postos e robôs a pedir com frequência,
 * não vale a pena ir à base de dados a cada pedido.
 */
class SitemapController extends Controller
{
    public function stations(): Response
    {
        $xml = Cache::remember('seo:sitemap-postos', now()->addHour(), function () {
            $base = rtrim((string) config('kerugas.site_url'), '/');
            $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];

            Station::query()
                ->where('status', 'ACTIVE')
                ->select(['id', 'name', 'updated_at', 'status_updated_at'])
                ->orderBy('id')
                ->chunk(1000, function ($stations) use (&$lines, $base) {
                    foreach ($stations as $station) {
                        $last = $station->status_updated_at && $station->status_updated_at->gt($station->updated_at ?? $station->status_updated_at)
                            ? $station->status_updated_at
                            : ($station->updated_at ?? $station->status_updated_at);
                        // URL amigável (Outubro 2026): /postos/12-posto-central-lubango
                        // — o mesmo que o site gera (src/app/paths.ts, stationPath).
                        $slug = Str::slug((string) $station->name);
                        $path = $slug !== '' ? "/postos/{$station->id}-{$slug}" : "/postos/{$station->id}";
                        $lines[] = '  <url><loc>' . e($base . $path) . '</loc>'
                            . ($last ? '<lastmod>' . $last->toAtomString() . '</lastmod>' : '')
                            . '<changefreq>hourly</changefreq><priority>0.8</priority></url>';
                    }
                });

            $lines[] = '</urlset>';

            return implode("\n", $lines) . "\n";
        });

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
