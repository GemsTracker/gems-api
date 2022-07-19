<?php

namespace Gems\Api\Event;

class SaveModel extends ModelEvent
{
    protected array $importData = [];

    /**
     * @return array
     */
    public function getImportData(): array
    {
        return $this->importData;
    }

    /**
     * @param array $importData
     */
    public function setImportData(array $importData): void
    {
        $this->importData = $importData;
    }
}