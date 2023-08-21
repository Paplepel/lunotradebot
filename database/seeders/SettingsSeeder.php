<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('settings')->insert([
            ['key' => 'LUNOAPI', 'value' => 'your_api_url'],
            ['key' => 'APISECRET', 'value' => 'your_api_secret'],
            ['key' => 'APIKEY', 'value' => 'your_api_key'],
            ['key' => 'RSI_BUY', 'value' => 'your_rsi_buy_value'],
            ['key' => 'RSI_SELL', 'value' => 'your_rsi_sell_value'],
            ['key' => 'SHORTTERM', 'value' => 'your_short_term_value'],
            ['key' => 'LONGTERM', 'value' => 'your_long_term_value'],
        ]);
    }
}

