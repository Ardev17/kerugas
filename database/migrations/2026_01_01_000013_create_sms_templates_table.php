<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Templates de SMS geridos dentro do KeruGás (Central de Operações).
 *
 * O gateway de SMS nunca é responsável pela lógica dos templates —
 * apenas recebe a mensagem final já renderizada. Ver
 * App\Services\Sms\SmsTemplateRenderer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique(); // ex: AVAILABILITY, NO_GAS, DELIVERY_REQUEST
            $table->string('description')->nullable();
            $table->text('content');
            $table->string('type')->default('GENERAL'); // GENERAL, AVAILABILITY, NO_GAS, DELIVERY_REQUEST, STATION_INFO, NOT_REGISTERED
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_templates');
    }
};
