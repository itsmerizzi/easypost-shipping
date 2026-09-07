<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The rate was bought at EasyPost but the label could not be downloaded or saved.
 * The shipment id and label URL are logged so the label can be recovered manually.
 */
class LabelNotPersistedException extends RuntimeException
{
    public function __construct(public readonly string $shipmentId, Throwable $previous)
    {
        parent::__construct("Label for shipment {$shipmentId} was bought but not persisted.", 0, $previous);
    }
}
