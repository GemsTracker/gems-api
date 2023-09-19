<?php

declare(strict_types=1);

namespace Gems\Api\Event;

use Zalt\Model\Data\DataReaderInterface;

class BeforeSaveModel extends ModelEvent
{
    public function __construct(
        DataReaderInterface $model,
        protected readonly array $beforeData,
    )
    {
        parent::__construct($model);
    }

    public function getBeforeData(): array
    {
        return $this->beforeData;
    }
}
