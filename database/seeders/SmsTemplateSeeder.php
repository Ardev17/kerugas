<?php

namespace Database\Seeders;

use App\Enums\SmsTemplateType;
use App\Models\SmsTemplate;
use Illuminate\Database\Seeder;

/**
 * Templates padrão da Central de Operações KeruGás (secções 18-21).
 * Idempotente: usa updateOrCreate por `code`, nunca duplica.
 *
 * REESCRITOS (Setembro 2026) para cumprir as regras de conteúdo
 * transacional da KambaSMS — ver "Boas Práticas para o Sender ID"
 * deles: nenhum link/URL (bloqueado automaticamente, erro 400) e
 * ≤160 caracteres por SMS (acima disso o SMS parte em segmentos
 * adicionais — ou, no caso da Kamba, mensagens fora das regras são
 * simplesmente recusadas antes do envio). As versões anteriores destes
 * templates tinham 193-234 caracteres E incluíam "www.kerugas.com" —
 * nenhuma delas teria sido aceite pela API deles. `{{postos}}` continua
 * dinâmico (ver `OperationService::formatStationsList()`, agora
 * limitado aos 2 primeiros postos para não voltar a ultrapassar o
 * limite com listas longas).
 */
class SmsTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'code' => 'AVAILABILITY',
                'name' => 'Disponibilidade de gás',
                'type' => SmsTemplateType::AVAILABILITY,
                'description' => 'Enviado quando existem postos com gás disponível no município consultado.',
                'content' => "KERUGÁS: {{quantidade_postos}} postos com gás em {{municipio}}:\n{{postos}}\nObrigado por usar o KeruGás.",
            ],
            [
                'code' => 'NO_GAS',
                'name' => 'Sem gás disponível',
                'type' => SmsTemplateType::NO_GAS,
                'description' => 'Enviado quando nenhum posto do município tem gás disponível no momento.',
                'content' => "KERUGÁS: Sem postos com gás em {{municipio}} neste momento. Tente novamente mais tarde. Obrigado por usar o KeruGás.",
            ],
            [
                'code' => 'DELIVERY_REQUEST',
                'name' => 'Pedido de entrega',
                'type' => SmsTemplateType::DELIVERY_REQUEST,
                'description' => 'Usado apenas quando o operador decide processar um pedido de entrega.',
                'content' => "KERUGÁS: Recebemos o seu pedido de gás. A nossa equipa vai contactar este número para confirmar a entrega.",
            ],
            [
                'code' => 'STATION_INFO',
                'name' => 'Informação de posto',
                'type' => SmsTemplateType::STATION_INFO,
                'description' => 'Informação detalhada sobre um posto específico.',
                'content' => "KERUGÁS: {{posto}} ({{municipio}}). Estado: {{disponibilidade}}. Fila: {{fila}}. Local: {{localizacao}}",
            ],
            [
                'code' => 'NOT_REGISTERED',
                'name' => 'Cliente não registado',
                'type' => SmsTemplateType::NOT_REGISTERED,
                'description' => 'Usado quando não sabemos o município do cliente porque ele ainda não se registou no site.',
                'content' => "KERUGÁS: Para indicarmos postos próximos, responda a esta SMS com a sua província e município.",
            ],
            // Subscrições mensais (Outubro 2026). Sem preço no texto: a
            // variável {{valor}} existe, mas só se usa quando o preço
            // estiver definido. Sem links (regra da KambaSMS), ≤160.
            [
                'code' => 'SUBSCRIPTION_REQUIRED',
                'name' => 'Subscrição necessária',
                'type' => SmsTemplateType::SUBSCRIPTION_REQUIRED,
                'description' => 'Para quem já usou as consultas grátis do mês e ainda não tem subscrição.',
                'content' => "KERUGÁS: Não podemos concluir esta consulta porque ainda não tem subscrição mensal. Para subscrever, peça o IBAN ao {{contacto}} e envie o comprovativo.",
            ],
            [
                'code' => 'SUBSCRIPTION_EXPIRED',
                'name' => 'Subscrição terminada',
                'type' => SmsTemplateType::SUBSCRIPTION_EXPIRED,
                'description' => 'Para quem já foi subscritor e a subscrição terminou.',
                'content' => "KERUGÁS: A sua subscrição mensal terminou e não podemos concluir esta consulta. Para renovar, peça o IBAN ao {{contacto}} e envie o comprovativo.",
            ],
            [
                'code' => 'SUBSCRIPTION_ACTIVATED',
                'name' => 'Subscrição ativada',
                'type' => SmsTemplateType::SUBSCRIPTION_ACTIVATED,
                'description' => 'Confirmação enviada ao cliente depois de o Super Admin ativar a subscrição.',
                'content' => "KERUGÁS: Subscrição ativa até {{validade}}. Já pode consultar gás por SMS sem limite. Obrigado por usar o KeruGás.",
            ],
            [
                'code' => 'OPERATOR_ANALYTICS',
                'name' => 'Aviso ao operador: analytics desativado',
                'type' => SmsTemplateType::OPERATOR_ANALYTICS,
                'description' => 'Enviado manualmente pelo Super Admin ao operador cujo posto tem o analytics desativado.',
                'content' => "KERUGÁS: Olá, caro operador. O analytics do seu posto está desativado. Atualize a subscrição para ter a versão completa. Contacto: {{contacto}}.",
            ],
        ];

        foreach ($templates as $template) {
            SmsTemplate::updateOrCreate(
                ['code' => $template['code']],
                [
                    'name' => $template['name'],
                    'type' => $template['type'],
                    'description' => $template['description'],
                    'content' => $template['content'],
                    'active' => true,
                ]
            );
        }
    }
}
