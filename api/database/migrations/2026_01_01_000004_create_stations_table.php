<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('province_id')->constrained('provinces')->restrictOnDelete();
            $table->foreignId('municipality_id')->constrained('municipalities')->restrictOnDelete();
            $table->string('address')->nullable();
            $table->string('reference_point')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('phone')->nullable();
            $table->string('sms_phone')->unique()->nullable();
            $table->string('opening_hours')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('status')->default('PENDING'); // ACTIVE, INACTIVE, PENDING, SUSPENDED
            $table->softDeletes();
            $table->timestamps();

            $table->index(['province_id', 'municipality_id']);
            $table->index(['latitude', 'longitude']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stations');
    }
};
