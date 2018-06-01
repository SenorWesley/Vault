<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Cache;
use GuzzleHttp\Client;

class Market extends Model
{
    protected $error;
    protected $coins;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'url', 'maker_fee',
        'taker_fee'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'url',
    ];

    /**
     * Creates an instance of a market.
     *
     * @param $market - the name of the market we wish to initialize
     * @return Market instance
     * @throws \InvalidArgumentException
     */
    public static function factory($market)
    {
        $market = ucfirst($market);

        if (false === self::all()->pluck('name')->search($market)) {
            throw new \InvalidArgumentException(sprintf('Invalid market "%s" provided', $market));
        }

        $marketClass = '\\App\\Markets\\' . ucfirst($market) . 'Wrapper';

        $instance = new $marketClass();

        $model = Market::where('name', $market)->first();
        $instance->fill($model->toArray());
        $instance->getCurrencies();
        return $instance;
    }

    public static function getPrice($coin, $fiat = 'EUR')
    {
        if (Cache::has("price:$coin:$fiat")) {
            return Cache::get("price:$coin:$fiat");
        }

        $url = "https://min-api.cryptocompare.com/data/price?fsym=$coin&tsyms=$fiat";
        $client = new Client();
        $response = $client->get($url);
        $result = json_decode($response->getBody());

        $price = $result->$fiat;

        Cache::set("price:$coin:$fiat", $price, 5);

        return sprintf("%.10f", $price);
    }

    /**
     * Create or update the amount of the wallet
     * @param $coin
     * @param null $amount
     * @return bool|wallet
     */
    public function wallet($coin, $amount = NULL)
    {

        if (!$this->isSupported($coin)) {
            Log::warning("$coin is not supported by $this->name, can not create wallet");
            return false;
        }


        $wallet = $this->wallets->where(["coin" => $coin])->firstOrCreate();

        if (!is_null($amount)) {

            Log::info("$this->name WALLET set to  $amount $coin");

            $wallet->update([
                    'balance' => $amount
                ]
            );

        }

        return $wallet;
    }



    /**
     * get last price
     * @param $coin
     * @return bool
     */
    public function getLastPrice($coin)
    {
        $rate = Coin::where('name', $coin)->where('market', $this->name)->value('last');


        if ($rate > 0) return $rate;

        Log::warning("No last price found for $coin");
        return false;

    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function coins() {
        return $this->hasMany(Coin::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function wallets() {
        return $this->hasMany(Wallet::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function transactions() {
        return $this->hasMany(Transaction::class);
    }

    public function isSupported($coin) {
        return $this->wallets->where(["coin" => $coin])->first != null;
    }
}

interface MarketContract {
    /**
     * BUY $amount $coin with BTC for rate $price
     *
     * @param $coin: String
     * @param $unit: Float
     * @param $price: Float
     * @return mixed
     */
    public function buy($coin, $unit, $price);

    /**
     * SELL $amount $coin for BTC per $rate
     *
     * @param $coin: String
     * @param $amount: Float
     * @param $rate: Float
     * @return mixed
     */
    public function sell($coin, $amount, $rate);

    /**
     * Deposit $amount of $coin to wallet
     *
     * @param $coin: String
     * @param $amount: Float
     * @return mixed
     */
    public function deposit($coin, $amount);

    /**
     * Withdraw $amount of $coin from wallet
     *
     * @param $coin : String
     * @param $amount : Float
     * @param $address : String
     * @return mixed
     */
    public function withdraw($coin, $amount, $address);


    /**
     * @param $coin: String
     * @param $amount: Float
     * @param $toMarket: String
     * @param $includeFee: Bool
     * @return mixed
     */
    public function transfer($coin, $amount, $toMarket, $includeFee = false);

    /**
     * Return this markets order book for $coin
     *
     * @param string $coin
     * @return bool
     */
    public function getOrderBook($coin);

    /**
     * @return bool
     */
    public function getData();

    /**
     * @return bool
     */
    public function getCurrencies();
}