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
        //dd($this->checkAccountBalance());
        foreach ($pairs as $pair) {
            $result = $this->signal($pair->pairs);
            //dd($pair);
            if ($result === "Buy") {
                echo "Buying " . $pair->pairs . "\n";
                dd($pair);
                // Execute buy order logic with budget and stop-loss
                $this->executeBuyOrder($pair->pairs, $pair->budget, $pair->stoploss);
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

    private function executeSellOrder($pair, $amountToSell, $stopLossPercentage)
    {
        $client = new Client();

        // Fetch the current ticker price
        $response = $client->get(env('LUNOAPI') . "/ticker?pair=$pair");
        $tickerData = json_decode($response->getBody(), true);
        $currentPrice = $tickerData['last_trade'];

        // Calculate the stop-loss price as a percentage below the current price
        $stopLossPrice = $currentPrice - ($currentPrice * ($stopLossPercentage / 100));

        // Prepare sell order parameters
        $postData = [
            'pair' => $pair,
            'type' => 'SELL',
            'volume' => $amountToSell,
            'price' => $currentPrice,
            'stop_price' => $stopLossPrice,
        ];

        // Replace with your Luno API key and secret
        $apiKey = env('APIKEY');
        $apiSecret = env('APISECRET');


        // Send the sell order request
        $response = $client->post(env('LUNOAPI').'/postorder', [
            'form_params' => $postData, // Use 'form_params' for form data
            'auth' => [$apiKey,$apiSecret] // Add authentication
        ]);

        // Handle the sell order response
        $sellOrderResponse = json_decode($response->getBody(), true);
        if (isset($sellOrderResponse['order_id'])) {
            echo "Sell order placed successfully. Order ID: {$sellOrderResponse['order_id']}\n";
            echo "Stop-loss price: $stopLossPrice\n";
        } else {
            echo "Failed to place sell order.\n";
        }
    }

    private function checkAccountBalance()
    {
        try {
            // Replace with your Luno API key and secret
            $apiKey = env('APIKEY');
            $apiSecret = env('APISECRET');

            // Create a Guzzle client instance
            $client = new Client();

            $options = [
                'auth' => [$apiKey,$apiSecret]
            ];

            //dd($headers);
            // Send the request to check your account balance
            $response = $client->get(env('LUNOAPI').'/balance?assets=ETH', $options);

            // Parse and display the response
            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody(), true);
                // Extract and display your account balances
                foreach ($data as $balance) {
                    //dd($balance);
                    $asset = $balance[0]['asset'];
                    $balanceValue = $balance[0]['balance'];
                    echo "Asset: $asset, Balance: $balanceValue\n";
                }
            } else {
                echo "Failed to fetch account balance. HTTP Status Code: " . $response->getStatusCode() . "\n";
            }
        } catch (\ErrorException $e) {
            // Handle exceptions
            echo "Error: " . $e->getMessage() . "\n";
        }
    }
    private function executeBuyOrder($pair, $budget, $stopLossPercentage)
    {
        $client = new Client();

        // Fetch the current ticker price
        $response = $client->get(env('LUNOAPI')."/ticker?pair=$pair");
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
        $apiKey = env('APIKEY');
        $apiSecret = env('APISECRET');

        $response = $client->post(env('LUNOAPI').'/postorder', [
            'form_params' => $postData, // Use 'form_params' for form data
            'auth' => [$apiKey,$apiSecret] // Add authentication
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
            $apiSecret = env('APISECRET');
            $baseUrl = env('LUNOAPI');

            // Create a Guzzle client instance
            $client = new Client();

            // Fetch historical price data for analysis
            $response = $client->get($baseUrl . "/trades?pair=" . $pair);

            if ($response->getStatusCode() === 200) {
                $trades = json_decode($response->getBody(), true);

                // Define parameters for the strategy
                $rsiThresholdBuy = env('RSI_BUY');    // RSI threshold for buy confirmation
                $rsiThresholdSell = env('RSI_SELL');  // RSI threshold for sell confirmation

                // Calculate short-term and long-term moving averages
                // Define your short-term and long-term intervals in minutes
                $shortTermInterval = env('SHORTTERM'); // 15 minutes for short-term indicator
                $longTermInterval = env('LONGTERM');   // 1 hour (60 minutes) for long-term indicator

                // Calculate the number of periods based on the intervals
                $shortTermPeriods = 24 * 60 / $shortTermInterval; // 24 hours of 15-minute periods
                $longTermPeriods = 24 * 60 / $longTermInterval;   // 24 hours of 1-hour periods

                $shortTermAverage = $this->calculateMovingAverage($trades['trades'], $shortTermPeriods);
                $longTermAverage = $this->calculateMovingAverage($trades['trades'], $longTermPeriods);

                // Calculate RSI values
                $rsiValues = $this->calculateRSI($trades, $shortTermPeriods);

                // Get the latest trade index
                $tradeCount = count($trades['trades']);
                $lastTradeIndex = $tradeCount - 1;

                // Check for moving average crossover
                $isShortAboveLong = $shortTermAverage[count($shortTermAverage) - 1] > $longTermAverage[count($longTermAverage) - 1];
                $wasShortAboveLong = $shortTermAverage[count($shortTermAverage) - 2] > $longTermAverage[count($longTermAverage) - 2];

                // Check RSI value
                $currentRSI = $rsiValues[count($rsiValues) - 1];

                if ($isShortAboveLong && !$wasShortAboveLong && $currentRSI < $rsiThresholdBuy) {
                    // Generate a buy signal
                    return "Buy";
                    // Implement code to execute a buy order using Luno API
                } elseif (!$isShortAboveLong && $wasShortAboveLong && $currentRSI > $rsiThresholdSell) {
                    // Generate a sell signal
                    return "Sell";
                    // Implement code to execute a sell order using Luno API
                } else {
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
