<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $table = 'transactions'; // Specify the table name if it's different

    protected $fillable = [
        'pair',
        'type',
        'amount',
        'price',
        'stop_loss',
    ];
}
