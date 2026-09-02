<?php

declare(strict_types=1);

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

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor_units' => 'integer',
        ];
    }

    protected static function newFactory(): ExpectedPaymentFactory
    {
        return ExpectedPaymentFactory::new();
    }
}
