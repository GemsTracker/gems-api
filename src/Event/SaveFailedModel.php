<?php

declare(strict_types=1);


namespace Gems\Api\Event;

use Exception;
use Zalt\Model\Data\DataReaderInterface;

class SaveFailedModel extends ModelEvent
{
    public function __construct(
        DataReaderInterface $model,
        protected readonly Exception $exception,
        protected readonly array $saveData,
    )
    {
        parent::__construct($model);
    }

    public function getException(): Exception
    {
        return $this->exception;
    }

    public function getSaveData(): array
    {
        return $this->saveData;
    }
}
