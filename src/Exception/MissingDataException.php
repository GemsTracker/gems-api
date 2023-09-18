<?php

namespace Gems\Api\Exception;

use Throwable;

class MissingDataException extends \Exception
{
    protected array $missingData;

    public function __construct(string $message = '', mixed $missingData = null, int $code = 0, Throwable|null $previous = null)
    {
        if (isset($missingData)) {
            if (!is_array($missingData)) {
                $missingData = [$missingData];
            }
            $this->missingData = $missingData;
        }
        parent::__construct($message, $code, $previous);
    }

    public function getMissingData(): array
    {
        return $this->missingData;
    }
}
