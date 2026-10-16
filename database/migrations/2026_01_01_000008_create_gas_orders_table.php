<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gas_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_reference')->unique(); // ex: KG-20260904-000123
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('customer_name');
            $table->string('customer_phone');

            $table->foreignId('province_id')->constrained('provinces')->restrictOnDelete();
            $table->foreignId('municipality_id')->constrained('municipalities')->restrictOnDelete();
            $table->string('neighborhood')->nullable();
            $table->string('address')->nullable();
            $table->string('reference_point')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('location_source')->default('MANUAL'); // GPS, MANUAL
            $table->decimal('location_accuracy', 8, 2)->nullable();

            $table->unsignedInteger('quantity')->default(1);
            $table->string('cylinder_type')->nullable(); // ex: 13kg — configurável, não fixo no código
            $table->text('notes')->nullable();

            $table->string('status')->default('PENDING');
            // PENDING, RECEIVED, CONTACTING, CONFIRMED, ASSIGNED, OUT_FOR_DELIVERY, DELIVERED, CANCELLED, FAILED
            $table->string('priority')->default('NORMAL'); // NORMAL, HIGH, URGENT

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('contacted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['province_id', 'municipality_id']);
            $table->index(['status', 'priority']);
            $table->index('customer_phone');
        });

        Schema::create('gas_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gas_order_id')->constrained('gas_orders')->cascadeOnDelete();
            $table->string('action'); // ex: "status_changed"
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('gas_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gas_order_events');
        Schema::dropIfExists('gas_orders');
    }
};
