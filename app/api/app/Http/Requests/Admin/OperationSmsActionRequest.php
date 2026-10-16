<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Usado tanto para pré-visualizar como para confirmar o envio de um
 * atendimento. A mensagem final é SEMPRE renderizada no servidor a
 * partir do template — nunca aceite em texto livre do cliente, para
 * impedir manipulação da mensagem enviada em nome do KeruGás.
 */
class OperationSmsActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gate feito pelo middleware `permission:sms.send`
    }

    public function rules(): array
    {
        return [
            'sms_template_id' => ['required', 'exists:sms_templates,id'],
        ];
    }
}
