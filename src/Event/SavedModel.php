<?php

declare(strict_types=1);

namespace Gems\Api\Event;

use Zalt\Model\Data\DataReaderInterface;

class SavedModel extends ModelEvent
{
    use EventDuration;

    public function __construct(
        DataReaderInterface $model,
        protected readonly array $newData,
        protected readonly array $oldData = [],
        float|null $start = null,
    )
    {
        parent::__construct($model);
        $this->start = $start ?? microtime(true);
    }

    public function getNewData(): array
    {
        return $this->newData;
    }

    public function getOldData(): array
    {
        return $this->oldData;
    }

    public function getUpdateDiffs(): array
    {
        if (!count($this->oldData)) {
            return array_keys($this->newData);
        }
        $diffValues = [];
        foreach($this->newData as $key=>$newValue) {
            if ($newValue instanceof \DateTimeInterface) {
                $storageFormat = 'Y-m-d H:i:s';
                if ($this->model->has($key, 'storageFormat')) {
                    $storageFormat = $this->model->get($key, 'storageFormat');
                }
                $newValue = $newValue->format($storageFormat);
            }
            if (array_key_exists($key, $this->oldData) && $newValue === $this->oldData[$key]) {
                continue;
            }
            $diffValues[$key] = $newValue;
        }
        return $diffValues;
    }
}
