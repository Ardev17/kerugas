<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained('stations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('gas_available');
            $table->string('queue_level')->nullable();
            $table->timestamps();

            $table->index(['station_id', 'created_at']);
            $table->unique(['station_id', 'user_id', 'created_at']);
        });

        Schema::create('station_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained('stations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating'); // 1-5
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['station_id', 'user_id']);
        });

        Schema::create('station_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained('stations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('content');
            $table->string('status')->default('PENDING'); // VISIBLE, PENDING, HIDDEN, DELETED
            $table->timestamps();

            $table->index(['station_id', 'status']);
        });

        Schema::create('station_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained('stations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason'); // GAS_UNAVAILABLE, QUEUE_INCORRECT, STATION_CLOSED, LOCATION_INCORRECT, MISLEADING_INFORMATION, OTHER
            $table->text('description')->nullable();
            $table->string('status')->default('OPEN'); // OPEN, UNDER_REVIEW, RESOLVED, REJECTED
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['station_id', 'status']);
        });

        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('station_id')->constrained('stations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'station_id']);
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('station_id')->nullable()->constrained('stations')->cascadeOnDelete();
            $table->foreignId('municipality_id')->nullable()->constrained('municipalities')->cascadeOnDelete();
            $table->unsignedInteger('max_distance_km')->nullable();
            $table->json('channels'); // ["sms","email","push","in_app"]
            $table->boolean('active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category'); // availability, queue, favorite, system, security, order
            $table->string('title');
            $table->text('body');
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('station_reports');
        Schema::dropIfExists('station_comments');
        Schema::dropIfExists('station_reviews');
        Schema::dropIfExists('station_confirmations');
    }
};
