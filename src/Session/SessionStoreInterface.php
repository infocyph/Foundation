<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session;

interface SessionStoreInterface
{
    public function delete(string $id): void;

    public function load(string $id, int $now): ?SessionPayload;

    public function prune(int $now, int $limit = 1_000): int;

    public function save(string $id, SessionPayload $payload): void;
}
