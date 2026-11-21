<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stripe_payments', function (Blueprint $table) {
            $table->string('dispute_id')->nullable()->unique();
            $table->string('dispute_status', 50)->nullable();
            $table->bigInteger('dispute_amount')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('total_disputed', 14, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('total_disputed');
        });

        Schema::table('stripe_payments', function (Blueprint $table) {
            $table->dropColumn(['dispute_id', 'dispute_status', 'dispute_amount']);
        });
    }
};
