<?php

declare(strict_types=1);


namespace Gems\Api\Event;

use Exception;

class SaveFailedModel extends ModelEvent
{
    /**
     * @var Exception
     */
    protected Exception $exception;

    /**
     * @var array
     */
    protected array $saveData;

    public function getException(): Exception
    {
        return $this->exception;
    }

    public function getSaveData(): array
    {
        return $this->saveData;
    }

    public function setException(Exception $exception): void
    {
        $this->exception = $exception;
    }

    public function setSaveData(array $data): void
    {
        $this->saveData = $data;
    }
}
