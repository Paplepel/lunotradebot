<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('pair');
            $table->string('type'); // 'BUY' or 'SELL'
            $table->decimal('amount', 16, 6); // Adjust the precision and scale as needed
            $table->decimal('price', 16, 6);
            $table->decimal('stop_loss', 16, 6);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
