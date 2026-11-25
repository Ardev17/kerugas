<?php

use App\Mail\NewContactMessageMail;
use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * CVE-2026-48019,injecção CRLF pela regra `email` por omissão do
 * Laravel. O Laravel 11 está em fim de vida (12/03/2026) e não recebe a
 * correcção do upstream, por isso o vector é fechado na aplicação:
 * validação à entrada e limpeza no ponto de uso do cabeçalho.
 */
it('recusa quebras de linha no nome do formulário de contacto', function () {
    Mail::fake();

    $resposta = $this->postJson('/api/v1/contact', [
        'name' => "Atacante\r\nBcc: vitima@exemplo.ao",
        'contact' => '+244 922 761 180',
        'message' => 'mensagem de teste',
    ]);

    $resposta->assertStatus(422)->assertJsonValidationErrors('name');
    Mail::assertNothingSent();
});

it('recusa quebras de linha no contacto do formulário', function () {
    Mail::fake();

    $resposta = $this->postJson('/api/v1/contact', [
        'name' => 'Aristides Chiovo',
        'contact' => "ardev@kerugas.ao\nBcc: vitima@exemplo.ao",
        'message' => 'mensagem de teste',
    ]);

    $resposta->assertStatus(422)->assertJsonValidationErrors('contact');
    Mail::assertNothingSent();
});

it('limpa o assunto mesmo que o nome gravado traga caracteres de controlo', function () {
    // Simula um registo que entrou antes desta regra existir.
    $mensagem = new ContactMessage([
        'name' => "Atacante\r\nBcc: vitima@exemplo.ao",
        'contact' => '+244 922 761 180',
        'message' => 'mensagem antiga',
    ]);

    $assunto = (new NewContactMessageMail($mensagem))->build()->subject;

    expect($assunto)->not->toContain("\r")
        ->and($assunto)->not->toContain("\n")
        ->and($assunto)->toBe('Novo contacto pelo site,Atacante Bcc: vitima@exemplo.ao');
});
