<?php

declare(strict_types=1);

namespace Gems\Api\Event;

class SavedModel extends ModelEvent
{
    use EventDuration;

    /**
     * @var array New data after save
     */
    protected array $newData;

    protected array $oldData;

    public function getNewData()
    {
        return $this->newData;
    }

    public function getOldData()
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
            if ($newValue instanceof \MUtil_Date) {
                $storageFormat = 'yyyy-MM-dd HH:mm:ss';
                if ($this->model->has($key, 'storageFormat')) {
                    $storageFormat = $this->model->get($key, 'storageFormat');
                }
                $newValue = $newValue->toString($storageFormat);
            }
            if (array_key_exists($key, $this->oldData) && $newValue === $this->oldData[$key]) {
                continue;
            }
            $diffValues[$key] = $newValue;
        }
        return $diffValues;
    }

    public function setNewData(array $newData): void
    {
        $this->newData = $newData;
    }

    public function setOldData(array $oldData): void
    {
        $this->oldData = $oldData;
    }
}
