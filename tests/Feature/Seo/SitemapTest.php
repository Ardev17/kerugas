<?php

use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * SEO (Outubro 2026): o sitemap dos postos só lista postos ATIVOS e não
 * apagados, com o domínio canónico.
 */
beforeEach(fn () => Cache::forget('seo:sitemap-postos'));

it('lista só os postos ativos e não apagados, com o domínio canónico', function () {
    config(['kerugas.site_url' => 'https://www.kerugas.ao']);
    $ativo = Station::factory()->create(['name' => 'Posto Central Lubango', 'status' => 'ACTIVE', 'status_updated_at' => now()]);
    $inativo = Station::factory()->create(['status' => 'INACTIVE']);
    $apagado = Station::factory()->create(['status' => 'ACTIVE']);
    $apagado->delete();

    $r = $this->get('/api/v1/seo/sitemap-postos.xml')->assertOk();

    expect($r->headers->get('Content-Type'))->toContain('application/xml');
    $xml = $r->getContent();
    expect($xml)->toContain('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">')
        ->and($xml)->toContain("<loc>https://www.kerugas.ao/postos/{$ativo->id}-posto-central-lubango</loc>")
        ->and($xml)->not->toContain("/postos/{$inativo->id}-")
        ->and($xml)->not->toContain("/postos/{$apagado->id}-");

    // XML válido
    expect(simplexml_load_string($xml))->not->toBeFalse();
});

it('é público (sem sessão)', function () {
    $this->get('/api/v1/seo/sitemap-postos.xml')->assertOk();
});
