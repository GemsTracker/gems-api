<?php

declare(strict_types=1);

namespace Gems\Api\Event;

use MUtil\Model\ModelAbstract;
use Symfony\Contracts\EventDispatcher\Event;

class ModelEvent extends Event
{
    /**
     * @var ModelAbstract
     */
    protected $model;

    public function __construct(ModelAbstract $model)
    {
        $this->model = $model;
    }

    public function getModel()
    {
        return $this->model;
    }
}
