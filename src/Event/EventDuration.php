<?php

namespace Gems\Api\Event;

use DateTimeInterface;

trait EventDuration
{
    protected DateTimeInterface|float $start;

    public function getDuration(): float|null
    {
        if (!isset($this->start)) {
            return null;
        }
        if ($this->start instanceof \DateTimeInterface) {
            $now = new \DateTimeImmutable();
            return $now->getTimestamp() - $this->start->getTimestamp();
        }
        $now = microtime(true);
        return $now - $this->start;
    }

    public function getDurationInSeconds(): int|null
    {
        $duration = $this->getDuration();
        if ($duration !== null) {
            return (int)$duration;
        }
        return null;
    }

    public function setStart(DateTimeInterface|float $start): void
    {
        $this->start = $start;
    }
}
