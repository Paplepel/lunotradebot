<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use App\Models\Tradepair;
use App\Models\Transaction;
use App\Models\TransactionHistory;
use Illuminate\Support\Facades\Log;
use App\Models\Setting;


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
            $active = Transaction::where('pair', $pair->pairs)->first();
            if($active)
            {
                Log::alert('Check Stop-loss for active coin '.$pair);
                if($this->isStopLossTriggered($pair->pairs))
                {
                    Log::alert('Stop-Loss has been triggered for '.$pair.' Selling coin');
                    $bal = $this->checkAccountBalance(str_replace('ZAR','',$pair->pairs));
                    $this->executeSellOrder($pair->pairs,$bal['balance'],5);
                    Log::alert('Stop-loss process complete for '.$pair);
                }else
                {
                    Log::alert('Check if active coin needs to be sold');
                    $result = $this->signal($pair->pairs);
                    if($result === "Sell")
                    {
                        Log::alert('Sell signal has been triggered for '.$pair);
                        $bal = $this->checkAccountBalance(str_replace('ZAR','',$pair));
                        $this->executeSellOrder($pair->pairs,$bal['balance'],5);
                    }
                    else
                    {
                        Log::alert('Still holding '.$pair);
                    }
                }
            }
            else
            {
                $result = $this->signal($pair->pairs);
                Log::alert('Check if coin needs to be bought '.$pair);
                if ($result === "Buy")
                {
                    Log::alert('Buy flag has been found for '.$pair);
                    Log::alert('Check if there is Funds to buy the Coin');
                    $bal = $this->checkAccountBalance('ZAR');
                    Log::alert('The current account balance is '.$bal['balance']);
                    if($bal['balance'] >= 200)
                    {
                        Log::alert('Funds are available');
                        Log::alert('Check if funds is more tha coin budget');
                        if($bal['balance'] >= $pair->budget)
                        {
                            Log::alert('There is more funds available than the coin budget using the Budget for '.$pair);
                            $this->executeBuyOrder($pair->pairs, $pair->budget, $pair->stoploss);
                            Log::alert('Bought coin at its budget '.$pair);
                        }else
                        {
                            Log::alert('There is not enough funds to match the Budget using all available funds');
                            $this->executeBuyOrder($pair->pairs, $bal['balance'], $pair->stoploss);
                            Log::alert('Bought coin for all available funds '.$pair);
                        }
                    }
                }
                else
                {
                    Log::alert('No signal for '.$pair);
                }
            }
        }
    }

    private function isStopLossTriggered($pair)
    {
        $client = new Client();
        $traidpair = Tradepair::Where('pairs',$pair)->first();
        //$stopLoss = $currentPrice - ($currentPrice * ($stopLossPercentage / 100));
        // Fetch the current ticker price
        $response = $client->get(Setting::where('key', 'LUNOAPI')->value('value') . "/ticker?pair=$pair");
        $tickerData = json_decode($response->getBody(), true);

        if (!isset($tickerData['last_trade'])) {
            Log::error('Failed to fetch ticker data. Aborting buy order.');
            return false;
        }

        $currentPrice = $tickerData['last_trade'];

        // Retrieve relevant transaction data from your database for the specified trading pair
        $transactions = Transaction::where('pair', $pair)->first();

        $stopLoss = $transactions->stop_loss;
        $highestPrice = $this->getHighestTradePrice($pair);

        // Check if the current price is below the trailing stop loss
        if ($currentPrice <= $stopLoss) {
            return true; // Trailing stop loss triggered
        }
        $highstop = $highestPrice - ($highestPrice * ($traidpair->stoploss / 100));
        if($highstop > $stopLoss )
        {
            Log::alert('Update the stop loss for '.$pair.' from '.$stopLoss.' to '.$highstop);
            $transactions->stop_loss = $highstop;
            $transactions->save();
        }


        return false; // Stop loss not triggered
    }

// Helper function to get the highest trade price for a pair
    private function getHighestTradePrice($pair)
    {
        $client = new Client();

        // Fetch the trade history for the specified pair
        $response = $client->get(Setting::where('key', 'LUNOAPI')->value('value') . "/trades?pair=$pair");
        $tradeData = json_decode($response->getBody(), true);

        if (!isset($tradeData['trades'])) {
            Log::error('Failed to fetch trade history. Unable to determine highest trade price.');
            return 0;
        }

        $highestPrice = 0;

        foreach ($tradeData['trades'] as $trade) {
            if ($trade['price'] > $highestPrice) {
                $highestPrice = $trade['price'];
            }
        }

        return $highestPrice;
    }


    private function executeSellOrder($pair, $amountToSell, $stopLossPercentage)
    {
        $client = new Client();

        // Fetch the current ticker price
        $response = $client->get(Setting::where('key', 'LUNOAPI')->value('value') . "/ticker?pair=$pair");
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
        $apiKey = Setting::where('key', 'APIKEY')->value('value');
        $apiSecret = Setting::where('key', 'APISECRET')->value('value');

        // Send the sell order request
        $response = $client->post(Setting::where('key', 'LUNOAPI')->value('value') . '/marketorder', [
            'form_params' => $postData, // Use 'form_params' for form data
            'auth' => [$apiKey, $apiSecret], // Add authentication
        ]);

        // Handle the sell order response
        $sellOrderResponse = json_decode($response->getBody(), true);
        if (isset($sellOrderResponse['order_id'])) {
            $transaction = Transaction::where('pair', $pair)->first();
            $transaction->delete();
            $history = new TransactionHistory;
            $history->pair = $pair;
            $history->type = 'SELL';
            $history->amount = $roundedNumber;
            $history->price = $currentPrice; // Set the actual current price
            $history->stop_loss = 0;
            $history->save();
            Log::alert('Luno has sold the coin');
            return true;

        } else {
            Log::error('Luno has failed to sell the coin');
        }
    }


    private function checkAccountBalance($cur)
    {
        try {
            // Replace with your Luno API key and secret
            $apiKey = Setting::where('key', 'APIKEY')->value('value');
            $apiSecret = Setting::where('key', 'APISECRET')->value('value');

            // Create a Guzzle client instance
            $client = new Client();

            $options = [
                'auth' => [$apiKey,$apiSecret]
            ];

            //dd($headers);
            // Send the request to check your account balance
            $response = $client->get(Setting::where('key', 'LUNOAPI')->value('value').'/balance?assets='.$cur, $options);

            // Parse and display the response
            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody(), true);
                // Extract and display your account balances
                return $data['balance'][0];
            } else {
                Log::error("Failed to fetch account balance for ".$cur.". HTTP Status Code: " . $response->getStatusCode());
            }
            } catch (\ErrorException $e) {
                // Handle exceptions
                Log::error("Error: " . $e->getMessage());
            }
    }

    private function executeBuyOrder($pair, $budget, $stopLossPercentage)
    {
        $client = new Client();

        $response = $client->get(Setting::where('key', 'LUNOAPI')->value('value') . "/ticker?pair=$pair");
        $tickerData = json_decode($response->getBody(), true);

        if (!isset($tickerData['last_trade'])) {
            Log::error('Failed to fetch ticker data. Aborting buy order');
            return false;
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
        $apiKey = Setting::where('key', 'APIKEY')->value('value');
        $apiSecret = Setting::where('key', 'APISECRET')->value('value');

        $response = $client->post(Setting::where('key', 'LUNOAPI')->value('value').'/marketorder', [
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
            $history = new TransactionHistory;
            $history->pair = $pair;
            $history->type = 'BUY';
            $history->amount = $roundedNumber;
            $history->price = $currentPrice; // Set the actual current price
            $history->stop_loss = $stopLoss;
            $history->save();
            return true;
        } else {
            Log::error('Failed to buy coin '.$pair);
            return false;
        }
    }

    private function signal($pair)
    {
        try {
            $baseUrl = Setting::where('key', 'LUNOAPI')->value('value');

            // Fetching the necessary configurations
            $shortTermPeriods = Setting::where('key', 'SHORTTERM')->value('value');
            $rsiThresholdBuy = Setting::where('key', 'RSI_BUY')->value('value');
            $rsiThresholdSell = Setting::where('key', 'RSI_SELL')->value('value');

            // Create a Guzzle client instance
            $client = new Client();
            $response = $client->get($baseUrl . "/trades?pair=" . $pair);

            if ($response->getStatusCode() === 200) {
                $trades = json_decode($response->getBody(), true);

                // Extract prices for MACD calculations
                $prices = array_column($trades['trades'], 'price');
                $volumes = array_column($trades['trades'], 'volume');
                $macdValues = $this->calculateMACD($prices);
                $signalLine = $this->calculateEMA($macdValues, 9);

                // Calculate RSI values
                $rsiValues = $this->calculateRSI($trades, $shortTermPeriods);

                $lastMACD = $macdValues[count($macdValues) - 1];
                $lastSignal = $signalLine[count($signalLine) - 1];
                $currentRSI = $rsiValues[count($rsiValues) - 1];

                // Determine the trend using MACD
                $isUptrend = $lastMACD > $lastSignal;

                $volumePeriod = 10; // You can set any number for n to decide how many previous trades you want to take the average from
                $averageVolume = array_sum(array_slice($volumes, -$volumePeriod)) / $volumePeriod;
                $currentVolume = $volumes[count($volumes) - 1];
                $isHighVolume = $currentVolume > $averageVolume;

                if ($isUptrend) {
                    if ($currentRSI < $rsiThresholdBuy && $isHighVolume) {
                        return "Buy";
                    } else {
                        return 'PASS';
                    }
                } else { // Ignore the uptrend for "Sell"
                    if ($currentRSI > $rsiThresholdSell && $isHighVolume) {
                        return "Sell";
                    } else {
                        return 'PASS';
                    }
                }

            } else {
                Log::error("Failed to fetch data from the API.");
                return 'NODATA';
            }
        } catch (\ErrorException $e) {
            // Handle exceptions
            Log::error("Error occurred: " . $e->getMessage());
            return 'NODATA';
        }
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

    private function calculateEMA($prices, $period) {
        $alpha = 2 / ($period + 1);
        $ema = [];

        // Use simple average for the first value
        $ema[0] = array_sum(array_slice($prices, 0, $period)) / $period;

        for ($i = $period; $i < count($prices); $i++) {
            $ema[] = ($prices[$i] - $ema[$i - $period]) * $alpha + $ema[$i - $period];
        }

        return $ema;
    }

    private function calculateMACD($prices) {
        $ema12 = $this->calculateEMA($prices, 12);
        $ema26 = $this->calculateEMA($prices, 26);

        $macd = [];
        $minLength = min(count($ema12), count($ema26));
        for ($i = 0; $i < $minLength; $i++) {
            $macd[] = $ema12[$i] - $ema26[$i];
        }

        return $macd;
    }


}
