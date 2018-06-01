<?php

namespace App\Markets;

use App\Models\Coin;
use ccxt\poloniex;
use App\Models\Market;
use App\Models\MarketContract;

class PoloniexWrapper extends Market implements MarketContract {

    /**
     * Returns instance of the market, straigt from ccxt.
     *
     */
    public final static function getExchange() {
        $exchange = new poloniex();
        $exchange->apiKey = env("POLONIEX_API_KEY", "");
        $exchange->secret = env("POLONIEX_API_SECRET", "");

        return $exchange;
    }

    /**
     * BUY $amount $coin with BTC for rate $price
     *
     * @param $coin : String
     * @param $unit : Float
     * @param $price : Float
     * @return mixed
     */
    public function buy($coin, $units, $price)
    {
        $fee = $this->maker_fee;

        $poloniex = PoloniexWrapper::getExchange();
        Log::info("BUY $units $coin with rate $price fee $fee%");

        $buyWallet = $this->wallet($coin);
        $sellWallet = $this->wallet('BTC');

        $feeAmount = bcmul(bcmul($price , $units), $fee/100);
        Log::info("Fee: $feeAmount");
        $cost = bcround(bcmul(bcmul($price, (1+$fee/100)), $units, 17), 16);
        Log::info("Total Cost: $cost BTC");

        $sellBalance = $sellWallet->balance;
        $buyBalance = $buyWallet->balance;

        if (bccomp($sellBalance, $cost) < 0) {
            $this->error = "Insufficient $cost BTC funds on $this->name to buy $units $coin.  Current balance:$sellBalance";
            Log::warning($this->error);
            return false;
        }

        $sellWallet->balance = bcsub($sellWallet->balance, $cost);
        Log::warning("$this->name WALLET BTC Before: $sellBalance SELL $cost After: {$sellWallet->balance}");
        $buyWallet->balance = bcadd($buyBalance, $units);
        Log::warning("$this->name WALLET $coin Before: $buyBalance BUY $units After: {$buyWallet->balance}");

        $transaction = $poloniex->create_limit_buy_order($coin, $units, $price);

        $buyWallet->save();
        $sellWallet->save();


        $this->transactions()->create([
            'name' => $this->name,
            'type' => "buy",
            'credit' => $units,
            "wallet" => $coin,
            "rate" => $price,
            "fee" => $feeAmount,
            "btc" => "-$cost",
            "transaction_id" => $transaction["id"]
        ]);

        return $cost;
    }

    /**
     * SELL $amount $coin for BTC per $rate
     *
     * @param $coin : String
     * @param $amount : Float
     * @param $rate : Float
     * @return mixed
     */
    public function sell($coin, $amount, $rate)
    {
        $fee = $this->taker_fee;
        $poloniex = PoloniexWrapper::getExchange();
        $buyWallet = $this->wallet('BTC');
        $sellWallet = $this->wallet($coin);

        $sellBalance = $sellWallet->balance;
        $buyBalance = $buyWallet->balance;

        if (bccomp($sellBalance, $amount) < 0) {
            $this->error = "Insufficent $amount $coin funds on $this->name to sell. Current balance: $sellBalance $coin";
            Log::warning($this->error);
            return false;
        }

        //calc fee
        $cost = bcmul($rate, $amount);
        Log::info("Cost without fee: $cost");

        $feeAmount = bcmul($cost, $fee / 100);
        Log::info("Fee: $feeAmount ");

        $cost = bcsub($cost, $feeAmount);

        $sellWallet->balance = bcsub($sellWallet->balance, $amount);
        Log::warning("$this->name WALLET $coin Before: $sellBalance SELL $cost After: {$sellWallet->balance}");

        $buyWallet->balance = bcadd($buyBalance, $cost);
        Log::warning("$this->name WALLET $coin Before: $buyBalance BUY $amount After: {$buyWallet->balance}");



        $transaction = $poloniex->create_limit_sell_order($coin, $amount, $rate);
        $buyWallet->save();
        $sellWallet->save();
        
        $this->transactions()->create([
            'name' => $this->name,
            'type' => "sell",
            'debit' => $amount,
            "wallet" => $coin,
            "rate" => $rate,
            "fee" => abs($feeAmount),
            "btc" => $cost,
            "transaction_id" => $transaction["id"]
        ]);

        return $cost;
    }

    /**
     * Deposit $amount of $coin to wallet
     *
     * @param $coin : String
     * @param $amount : Float
     * @return mixed
     */
    public function deposit($coin, $amount)
    {

        // TODO: Implement deposit() method.
    }

    /**
     * Withdraw $amount of $coin from wallet
     *
     * @param $coin : String
     * @param $amount : Float
     * @return mixed
     */
    public function withdraw($coin, $amount, $address)
    {
        $poloniex = PoloniexWrapper::getExchange();
//    array_keys(\App\Markets\PoloniexWrapper::getExchange()->fetch_balance());
        if ($amount <= 0) return false;

        $wallet = $this->wallet($coin);

        if (!$wallet) return false;

        if (bccomp($wallet->balance, $amount) <= 0) {
            Log::error("Insufficient $coin funds to withdraw $amount. Current balance: {$wallet->balance}");
            return false;
        }

        if (!isset($this->coins[$coin])) return false;
        $fee = $this->coins[$coin];

        if (bccomp($amount, $fee) < 0) {
            $this->error = "Cannot withdraw less than the minimum fee $fee $coin";
            Log::warning($this->error);
            return false;
        }

        $poloniex->withdraw($coin, $amount, $address);

        $wallet->balance = bcsub($wallet->balance, $amount);
        $wallet->save();

        $wallet->transactions()->create([
            'market' => $this->name,
            'wallet' => $coin,
            'type' => 'withdraw',
            'debit' => $amount,
            'receiving_address' => $address,
            'rate' => 0,
            'fee' => $fee,
            'btc' => ($coin == 'BTC') ? bcsub($amount,  $fee) : 0
        ]);

        return ($amount - $fee);
    }

    /**
     * @param $coin : String
     * @param $amount : Float
     * @param $toMarket : String
     * @param $includeFee : Bool
     * @return mixed
     */
    public function transfer($coin, $amount, $toMarket, $includeFee = false)
    {
        // TODO: Implement transfer() method.
    }

    /**
     * Return this markets order book for $coin
     *
     * @param string $coin
     * @return array|bool
     */
    public function getOrderBook($coin)
    {
        $orderBook = \App\Markets\PoloniexWrapper::getExchange()->fetch_order_book("{$coin}/BTC");

        if (empty($orderBook->asks)) {
            return false;
        }

        if (empty($orderBook->bids)) {
            return false;
        }

        $buy = $orderBook->bids[0];
        $sell = $orderBook->asks[0];
        $quantity = [
            'bid' => sprintf("%.10f", $buy[1]),
            'ask' => sprintf("%.10f", $sell[1]),
            'bid_rate' => $buy[0],
            'ask_rate' => $sell[0],
        ];

        return $quantity;
    }

    /**
     */
    public function getData()
    {
        $tickers = \App\Markets\PoloniexWrapper::getExchange()->fetch_tickers();

        if (!$tickers)
            return false;

        foreach ($tickers as $symbol => $coin) {
            if (strpos($symbol, 'BTC_') === false) continue;

            $symbol = str_replace('BTC_', '', $symbol);

            Coin::create([
                'market' => $this->name,
                'name' => $symbol,
                'ask' => $coin->lowestAsk,
                'bid' => $coin->highestBid,
                'last' => $coin->last
            ]);
        }
    }

    /**
     * @return bool
     */
    public function getCurrencies()
    {
        $currencies = \App\Markets\PoloniexWrapper::getExchange()->fetch_currencies();
        if (!$currencies)
            return false;

        foreach ($currencies as $symbol => $currency) {

            if ($currency->disabled == 1 || $currency->delisted == 1)
                continue;

            $coins[$symbol] = $currency->txFee;
        }

        $this->coins = $coins;
    }
}