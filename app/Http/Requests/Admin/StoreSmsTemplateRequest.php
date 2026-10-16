<?php

namespace App\Http\Requests\Admin;

use App\Enums\SmsTemplateType;
use App\Services\Sms\SmsTemplateRenderer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSmsTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gate feito pelo middleware `permission:sms.templates.manage`
    }

    public function rules(): array
    {
        $templateId = $this->route('smsTemplate')?->id;

        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:50', Rule::unique('sms_templates', 'code')->ignore($templateId)],
            'description' => ['nullable', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:1000'],
            'type' => ['required', Rule::enum(SmsTemplateType::class)],
            'active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $errors = app(SmsTemplateRenderer::class)->validate((string) $this->input('content', ''));

            foreach ($errors as $error) {
                $validator->errors()->add('content', $error);
            }
        });
    }
}
