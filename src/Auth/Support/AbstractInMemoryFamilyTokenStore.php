<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;

abstract class AbstractInMemoryFamilyTokenStore
{
    /**
     * @var array<string, object>
     */
    protected array $records = [];

    /**
     * @var array<string, true>
     */
    protected array $revokedFamilies = [];

    public function __construct(
        protected readonly ClockInterface $clock = new SystemClock(),
    ) {}

    abstract protected function familyId(object $record): string;

    abstract protected function revokeRecord(object $record, int $revokedAt): object;

    abstract protected function rotateRecord(object $record, int $rotatedAt): object;

    protected function findStored(string $recordId): ?object
    {
        return $this->records[$recordId] ?? null;
    }

    protected function revokeStoredFamily(string $familyId): void
    {
        $this->revokedFamilies[$familyId] = true;

        foreach ($this->records as $recordId => $record) {
            if ($this->familyId($record) !== $familyId) {
                continue;
            }

            $this->records[$recordId] = $this->revokeRecord($record, $this->clock->now());
        }
    }

    protected function rotateStored(string $recordId, object $replacement, string $replacementId): void
    {
        $current = $this->records[$recordId] ?? null;

        if ($current !== null) {
            $this->records[$recordId] = $this->rotateRecord($current, $this->clock->now());
        }

        $this->records[$replacementId] = $replacement;
    }

    protected function rotateStoredIfActive(AbstractFamilyTokenRecord $expected, AbstractFamilyTokenRecord $replacement): bool
    {
        $actual = $this->records[$expected->id] ?? null;
        if (!$actual instanceof AbstractFamilyTokenRecord
            || $actual->accountId !== $expected->accountId
            || $actual->familyId !== $expected->familyId
            || $replacement->accountId !== $expected->accountId
            || $replacement->familyId !== $expected->familyId
            || $actual->rotatedAt !== null
            || $actual->isRevoked()
            || $actual->isExpiredAt($this->clock->now())
            || isset($this->revokedFamilies[$actual->familyId])
            || isset($this->records[$replacement->id])
        ) {
            return false;
        }

        $this->records[$expected->id] = $this->rotateRecord($actual, $this->clock->now());
        $this->records[$replacement->id] = $replacement;

        return true;
    }

    protected function saveStored(object $record, string $recordId): void
    {
        $this->records[$recordId] = $record;
    }

    protected function wasStoredFamilyRevoked(string $familyId): bool
    {
        return isset($this->revokedFamilies[$familyId]);
    }
}
