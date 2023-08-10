<?php

declare(strict_types=1);

namespace Gems\Api\Event;

use Zalt\Model\Data\DataReaderInterface;
use Symfony\Contracts\EventDispatcher\Event;

class ModelEvent extends Event
{
    public function __construct(
        protected readonly DataReaderInterface $model)
    {
        $this->model = $model;
    }

    public function getModel()
    {
        return $this->model;
    }
}
