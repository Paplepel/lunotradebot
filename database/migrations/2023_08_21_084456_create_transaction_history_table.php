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
        Schema::create('transaction_history', function (Blueprint $table) {
            $table->id();
            $table->string('pair');
            $table->string('type');
            $table->decimal('amount', 18, 6); // 18 total digits with up to 6 decimal places
            $table->decimal('price', 18, 2); // 18 total digits with up to 2 decimal places
            $table->decimal('stop_loss', 18, 2); // 18 total digits with up to 2 decimal places
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_history');
    }
};
