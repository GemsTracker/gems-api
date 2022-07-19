<?php

namespace Gems\Api\Exception;

use Throwable;

class ModelValidationException extends ModelException
{
    /**
     * @var array List of errors
     */
    protected array $errors;

    public function __construct(string $message = "", array $errors = [], int $code = 0, Throwable|null $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    /**
     * Get the errors supplied in the Exception
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}