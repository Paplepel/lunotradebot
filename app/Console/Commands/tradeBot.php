<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use App\Models\Tradepair;
use App\Models\Transaction;


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
        $bal = $this->checkAccountBalance('ZAR');
        //dd($bal);
        dd($this->executeBuyOrder('ETHZAR',$bal['balance'],5));
        //dd($this->executeSellOrder('ETHZAR',$bal['balance'],5));
       //dd($this->checkAccountBalance('ETH'));
        foreach ($pairs as $pair) {
            $result = $this->signal($pair->pairs);
            dd($pair);
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

    private function isStopLossTriggered($pair)
    {

        $client = new Client();

        $response = $client->get(env('LUNOAPI') . "/ticker?pair=$pair");
        $tickerData = json_decode($response->getBody(), true);
        $tickerData = json_decode($response->getBody(), true);

        if (!isset($tickerData['last_trade'])) {
            echo "Failed to fetch ticker data. Aborting buy order.\n";
            return false;
        }
        $currentPrice = $tickerData['last_trade'];
        // Retrieve relevant transaction data from your database for the specified trading pair
        $transactions = Transaction::where('pair', $pair)->first();

        // Check if any of the transactions have a stop loss condition that is triggered
        if($transactions->stop_loss >= $currentPrice)
        {
            return true;
        }

        return false; // Stop loss not triggered
    }

    private function executeSellOrder($pair, $amountToSell, $stopLossPercentage)
    {
        $client = new Client();

        // Fetch the current ticker price
        $response = $client->get(env('LUNOAPI') . "/ticker?pair=$pair");
        $tickerData = json_decode($response->getBody(), true);
        $currentPrice = $tickerData['last_trade'];

        // Calculate the cost as 0.599% of the amount to sell
        $cost = $amountToSell * 0.00599; // 0.599% as a decimal

        // Deduct the cost from the amount to sell
        $amountToSell -= $cost;

        // Round down the amount to sell to 6 decimal places
        $roundedNumber = floor($amountToSell * 1000000) / 1000000;

        // Prepare sell order parameters
        $postData = [
            'pair' => $pair,
            'type' => 'SELL',
            'base_volume' => $roundedNumber,
        ];

        // Replace with your Luno API key and secret
        $apiKey = env('APIKEY');
        $apiSecret = env('APISECRET');

        // Send the sell order request
        try {
            $response = $client->post(env('LUNOAPI') . '/marketorder', [
                'form_params' => $postData, // Use 'form_params' for form data
                'auth' => [$apiKey, $apiSecret], // Add authentication
            ]);
        } catch (\GuzzleHttp\Exception\ClientException  $e) {
            // Get the response from the exception
            $response = $e->getResponse();

            // Get the status code
            $statusCode = $response->getStatusCode();

            // Get the response body as a string
            $body = $response->getBody()->getContents();

            // Get the response headers as an array
            $headers = $response->getHeaders();

            // Display or log the error information
            echo "Status Code: $statusCode\n";
            echo "Response Body: $body\n";
            echo "Response Headers:\n";
            print_r($headers);
        }

        // Handle the sell order response
        $sellOrderResponse = json_decode($response->getBody(), true);
        if (isset($sellOrderResponse['order_id'])) {
            $transaction = Transaction::where('pair', $pair)->first();
            $transaction->delete();
        } else {
            echo "Failed to place sell order.\n";
        }
    }


    private function checkAccountBalance($cur)
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
            $response = $client->get(env('LUNOAPI').'/balance?assets='.$cur, $options);

            // Parse and display the response
            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody(), true);
                // Extract and display your account balances
                return $data['balance'][0];
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

        $response = $client->get(env('LUNOAPI') . "/ticker?pair=$pair");
        $tickerData = json_decode($response->getBody(), true);
        $tickerData = json_decode($response->getBody(), true);

        if (!isset($tickerData['last_trade'])) {
            echo "Failed to fetch ticker data. Aborting buy order.\n";
            return;
        }
        $currentPrice = $tickerData['last_trade'];
        $cost = $budget * 0.00599; // 0.599% as a decimal

        // Deduct the cost from the amount to sell
        $budget -= $cost;

        $roundedNumber = floor($budget * 100) / 100;

        $stopLoss = $currentPrice - ($currentPrice * ($stopLossPercentage / 100));
        // Prepare buy order parameters
        $postData = [
            'pair' => $pair,
            'type' => 'BUY',
            'counter_volume' => $roundedNumber,
        ];


        // Replace with your Luno API key and secret
        $apiKey = env('APIKEY');
        $apiSecret = env('APISECRET');

        $response = $client->post(env('LUNOAPI').'/marketorder', [
            'form_params' => $postData, // Use 'form_params' for form data
            'auth' => [$apiKey,$apiSecret] // Add authentication
        ]);

        // Handle the buy order response
        $buyOrderResponse = json_decode($response->getBody(), true);
        //dd($buyOrderResponse);
        if (isset($buyOrderResponse['order_id'])) {
            $transaction = new Transaction;
            $transaction->pair = $pair;
            $transaction->type = 'BUY';
            $transaction->amount = $roundedNumber;
            $transaction->price = $currentPrice; // Set the actual current price
            $transaction->stop_loss = $stopLoss;
            $transaction->save();
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
