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
        Schema::table('tradepairs', function (Blueprint $table) {
            $table->decimal('budget', 10, 2)->nullable();
            $table->decimal('stoploss', 10, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('tradepairs', function (Blueprint $table) {
            $table->dropColumn('budget');
            $table->dropColumn('stoploss');
        });
    }
};
