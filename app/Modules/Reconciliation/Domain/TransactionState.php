<?php

namespace App\Modules\Reconciliation\Domain;

enum TransactionState: string
{
    case Pending = 'Pending';
    case Matched = 'Matched';
    case Unmatched = 'Unmatched';
    case NeedsReview = 'NeedsReview';
    case Reconciled = 'Reconciled';
    case Rejected = 'Rejected';
}
