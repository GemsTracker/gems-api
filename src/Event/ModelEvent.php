<?php

declare(strict_types=1);

namespace Gems\Api\Event;

use Symfony\Contracts\EventDispatcher\Event;

class ModelEvent extends Event
{
    /**
     * @var \MUtil_Model_ModelAbstract
     */
    protected $model;

    public function __construct(\MUtil_Model_ModelAbstract $model)
    {
        $this->model = $model;
    }

    public function getModel()
    {
        return $this->model;
    }
}
