<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

trait TracksSuccessfulStatus
{
    /**
     * @return list<\UnitEnum>
     */
    abstract protected function successfulStatuses(): array;

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function successful(): bool
    {
        return in_array($this->status, $this->successfulStatuses(), true);
    }
}
