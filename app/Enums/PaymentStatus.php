<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';

    case Paid = 'paid';

    case Failed = 'failed';

    /**
     * Short label for the status badge in the purchase history.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
        };
    }
}
