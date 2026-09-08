<?php

namespace App\Exceptions;

use RuntimeException;

class NoUspsRateException extends RuntimeException
{
    public function __construct(public readonly string $shipmentId)
    {
        parent::__construct("EasyPost returned no USPS rate for shipment {$shipmentId}.");
    }
}
