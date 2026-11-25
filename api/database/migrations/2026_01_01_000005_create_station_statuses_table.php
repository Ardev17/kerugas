<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained('stations')->cascadeOnDelete();
            $table->boolean('gas_available')->default(false);
            $table->string('queue_level')->default('LOW'); // LOW, MEDIUM, HIGH
            $table->string('source'); // OFFICIAL_SMS, OPERATOR, COMMUNITY, ADMIN
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('confidence_score')->default(50); // 0-100
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['station_id', 'created_at']);
        });

        // Snapshot "atual" desnormalizado para leitura rápida (evita agregações constantes)
        Schema::table('stations', function (Blueprint $table) {
            $table->boolean('current_gas_available')->default(false)->after('status');
            $table->string('current_queue_level')->default('LOW')->after('current_gas_available');
            $table->unsignedTinyInteger('current_confidence_score')->default(0)->after('current_queue_level');
            $table->timestamp('status_updated_at')->nullable()->after('current_confidence_score');
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn(['current_gas_available', 'current_queue_level', 'current_confidence_score', 'status_updated_at']);
        });
        Schema::dropIfExists('station_statuses');
    }
};
