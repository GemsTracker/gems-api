<?php

namespace Gems\Api\Event;

use DateTimeInterface;

trait EventDuration
{
    protected DateTimeInterface|float $start;

    public function getDurationInSeconds(): int|null
    {
        if ($this->start instanceof \DateTimeInterface) {
            $now = new \DateTimeImmutable();
            return $now->getTimestamp() - $this->start->getTimestamp();
        }
        if (is_numeric($this->start)) {
            $now = microtime(true);
            return $now - $this->start;
        }
        return null;
    }

    public function setStart(DateTimeInterface|float $start): void
    {
        $this->start = $start;
    }
}
