<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;


class tradeBot extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'trade:bot';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Trade bot for Luno';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $apiKey = 'zyzDM2-Gjqv3XgursNo1Fd51kmeWahfbe7ZtnK7o81Y';
        $apiSecret = 'e5hhfh4rhqnpq';
        $baseUrl = 'https://api.luno.com/api/1';

        // Create a Guzzle client instance
        // Create a Guzzle client instance
        $client = new Client();

        // Fetch historical price data for analysis
        $response = $client->get("$baseUrl/trades?pair=XBTZAR");

        if ($response->getStatusCode() === 200) {
            $trades = json_decode($response->getBody(), true);
            //dd($trades);
            //dd($this->calculateRSI($trades,14));
            // Define parameters for the strategy
            $shortTermPeriod = 10; // Number of days for short-term moving average
            $longTermPeriod = 30;  // Number of days for long-term moving average
            $rsiThreshold = 30;    // RSI threshold for buy confirmation

            // Calculate short-term and long-term moving averages
            $shortTermAverage = $this->calculateMovingAverage($trades['trades'], $shortTermPeriod);
            $longTermAverage = $this->calculateMovingAverage($trades['trades'], $longTermPeriod);
            // Calculate RSI values
            $rsiValues = $this->calculateRSI($trades,$shortTermPeriod);

            // Get the latest trade index
            $tradeCount = count($trades['trades']);
            $lastTradeIndex = $tradeCount - 1;
            // Check for moving average crossover
            //dd($shortTermAverage);
            $isShortAboveLong = $shortTermAverage[count($shortTermAverage)-1] > $longTermAverage[count($longTermAverage)-1];
            $wasShortAboveLong = $shortTermAverage[count($shortTermAverage) - 2] > $longTermAverage[count($longTermAverage) - 2];
            // Check RSI value
            $currentRSI = $rsiValues[count($rsiValues)-1];

            if ($isShortAboveLong && !$wasShortAboveLong && $currentRSI < $rsiThreshold) {
                // Generate a buy signal
                echo "Generate Buy Signal";
                // Implement code to execute a buy order using Luno API
            } elseif (!$isShortAboveLong && $wasShortAboveLong) {
                // Generate a sell signal
                echo "Generate Sell Signal";
                // Implement code to execute a sell order using Luno API
            }
            else{
                echo 'No Signal';
            }

        } else {
            echo "Failed to fetch data from the API.";
        }

    }
    // Calculate the moving average for a given period
    private function calculateMovingAverage($data, $period) {
        $movingAverages = [];

        for ($i = $period - 1; $i < count($data); $i++) {
            $sum = 0;

            // Calculate the sum of prices for the specified period
            for ($j = $i - $period + 1; $j <= $i; $j++) {
                $sum += $data[$j]['price']; // Assuming the data is in the format ['price' => 123.45, ...]
            }

            // Calculate the moving average for the current data point
            $average = $sum / $period;
            $movingAverages[] = $average;
        }

        return $movingAverages;
    }

    // Calculate the RSI values
    private function calculateRSI($tradeData, $period) {
        // Assuming you have the trade data in the $tradeData array

// Convert timestamps from milliseconds to seconds
        foreach ($tradeData['trades'] as &$trade) {
            $trade['timestamp'] = $trade['timestamp'] / 1000;
        }

// Sort the data by timestamp in ascending order
        usort($tradeData['trades'], function($a, $b) {
            return $a['timestamp'] - $b['timestamp'];
        });

        $gains = [];
        $losses = [];
        $rsiValues = [];

        for ($i = $period; $i < count($tradeData['trades']); $i++) {
            $priceDiff = $tradeData['trades'][$i]['price'] - $tradeData['trades'][$i - 1]['price'];

            if ($priceDiff > 0) {
                $gains[] = $priceDiff;
                $losses[] = 0;
            } else {
                $gains[] = 0;
                $losses[] = abs($priceDiff);
            }

            if ($i >= $period) {
                // Calculate average gains and losses over the period
                $averageGain = array_sum(array_slice($gains, -$period)) / $period;
                $averageLoss = array_sum(array_slice($losses, -$period)) / $period;

                // Calculate relative strength (RS)
                if ($averageLoss == 0) {
                    $rs = 100; // To avoid division by zero
                } else {
                    $rs = $averageGain / $averageLoss;
                }

                // Calculate RSI
                $rsi = 100 - (100 / (1 + $rs));
                $rsiValues[] = $rsi;
            }
        }
        // The $rsiValues array will contain the calculated RSI values.
        return $rsiValues;


    }

}
