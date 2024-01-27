<?php

namespace EST;

use RuntimeException;
use Throwable;

class ReaderException extends RuntimeException
{
    public function __construct(string $message, Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}