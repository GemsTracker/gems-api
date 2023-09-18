<?php

namespace Gems\Api\Exception;

use Exception;
use Throwable;

class IncorrectDataException extends Exception
{
    protected array $incorrectData = [];

    public function __construct(string $message = '', mixed $incorrectData = null, int $code = 0, Throwable|null $previous = null)
    {
        if (isset($incorrectData)) {
            if (!is_array($incorrectData)) {
                $incorrectData = [$incorrectData];
            }
            $this->incorrectData = $incorrectData;
        }
        parent::__construct($message, $code, $previous);
    }

    public function getIncorrectData(): array
    {
        return $this->incorrectData;
    }
}