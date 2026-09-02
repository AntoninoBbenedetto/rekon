<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

class TransactionProjection extends Model
{
    protected $table = 'transactions_read_model';

    protected $primaryKey = 'transaction_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'transaction_id',
        'state',
        'version',
        'amount_minor_units',
        'currency',
        'reference',
        'statement_date',
        'matched_expected_payment_id',
        'imported_at',
        'updated_at',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'imported_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
