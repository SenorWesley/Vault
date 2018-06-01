<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'market', 'wallet', 'type',
        'debit', 'credit', 'rate',
        'fee', 'btc', 'receiving_address',
        'transaction_id',
    ];

    public function market() {
        return $this->belongsTo(Market::class);
    }

    public function wallet() {
        return $this->belongsTo(Wallet::class);
    }
}
