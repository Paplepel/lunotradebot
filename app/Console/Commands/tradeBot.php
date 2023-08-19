<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use App\Models\Tradepair;


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
        $pairs = Tradepair::all();
        foreach ($pairs as $pair) {
            $result = $this->signal($pair->pairs);

            if ($result === "Buy") {
                echo "Buying " . $pair->pairs . "\n";
                // Execute buy order logic with budget and stop-loss
                $budget = 1000; // Set your budget here
                $stopLossPrice = 950; // Set your stop-loss price here
                $this->executeBuyOrder($pair->pairs, $budget, $stopLossPrice);
            } elseif ($result === "Sell") {
                echo "Selling " . $pair->pairs . "\n";
                // Execute sell order logic here
                // ...
            } elseif ($result === "NODATA") {
                echo "No recent trade data for " . $pair->pairs . "\n";
            } elseif ($result === "PASS") {
                echo "No trade signal for " . $pair->pairs . "\n";
            }
        }
    }

    private function executeBuyOrder($pair, $budget, $stopLossPercentage)
    {
        $client = new Client();

        // Fetch the current ticker price
        $response = $client->get("https://api.luno.com/api/1/ticker?pair=$pair");
        $tickerData = json_decode($response->getBody(), true);
        $currentPrice = $tickerData['last_trade'];

        // Calculate the amount of cryptocurrency to buy
        $amountToBuy = $budget / $currentPrice;

        // Calculate the stop-loss price as a percentage below the current price
        $stopLossPrice = $currentPrice - ($currentPrice * ($stopLossPercentage / 100));

        // Prepare buy order parameters
        $postData = [
            'pair' => $pair,
            'type' => 'BUY',
            'volume' => $amountToBuy,
            'price' => $currentPrice,
            'stop_price' => $stopLossPrice, // Specify the stop-loss price here
        ];

        // Replace with your Luno API key and secret
        $apiKey = 'YOUR_API_KEY';
        $apiSecret = 'YOUR_API_SECRET';

        // Calculate signature
        $nonce = time();
        $signature = hash_hmac('sha256', $apiKey . $nonce . json_encode($postData), $apiSecret);

        // Send the buy order request
        $response = $client->post('https://api.luno.com/api/1/postorder', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => "Bearer $apiKey:$nonce:$signature",
            ],
            'json' => $postData,
        ]);

        // Handle the buy order response
        $buyOrderResponse = json_decode($response->getBody(), true);
        if (isset($buyOrderResponse['order_id'])) {
            echo "Buy order placed successfully. Order ID: {$buyOrderResponse['order_id']}\n";
            echo "Stop-loss price: $stopLossPrice\n";
        } else {
            echo "Failed to place buy order.\n";
        }
    }

    private function signal($pair)
    {
        try {
        $apiKey = env('APIKEY');
        $apiSecret = env('apisecret');
        $baseUrl = env('LUNOAPI');

        // Create a Guzzle client instance
        // Create a Guzzle client instance
        $client = new Client();

        // Fetch historical price data for analysis
        $response = $client->get($baseUrl."/trades?pair=".$pair);

        if ($response->getStatusCode() === 200) {
            $trades = json_decode($response->getBody(), true);
            //dd($trades);
            //dd($this->calculateRSI($trades,14));
            // Define parameters for the strategy
            $rsiThreshold = 20;    // RSI threshold for buy confirmation

            // Calculate short-term and long-term moving averages
            // Define your short-term and long-term intervals in minutes
            $shortTermInterval = 15; // 15 minutes for short-term indicator
            $longTermInterval = 60;  // 1 hour (60 minutes) for long-term indicator

            // Calculate the number of periods based on the intervals
            $shortTermPeriods = 24 * 60 / $shortTermInterval; // 24 hours of 15-minute periods
            $longTermPeriods = 24 * 60 / $longTermInterval;    // 24 hours of 1-hour periods

            $shortTermAverage = $this->calculateMovingAverage($trades['trades'], $shortTermPeriods);
            $longTermAverage = $this->calculateMovingAverage($trades['trades'], $longTermPeriods);
            // Calculate RSI values
            $rsiValues = $this->calculateRSI($trades,$shortTermPeriods);

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
                return "Buy";
                // Implement code to execute a buy order using Luno API
            } elseif (!$isShortAboveLong && $wasShortAboveLong) {
                // Generate a sell signal
                return "Sell";
                // Implement code to execute a sell order using Luno API
            }
            else{
                return 'PASS';
            }

        } else {
            echo "Failed to fetch data from the API.";
        }
        } catch (\ErrorException $e) {
            // Handle exceptions
            return 'NODATA';
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