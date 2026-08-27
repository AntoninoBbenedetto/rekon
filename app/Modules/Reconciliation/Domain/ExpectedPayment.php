<?php

namespace App\Modules\Reconciliation\Domain;

use Database\Factories\ExpectedPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpectedPayment extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'amount_minor_units', 'currency', 'reference'];

    protected static function newFactory(): ExpectedPaymentFactory
    {
        return ExpectedPaymentFactory::new();
    }
}
