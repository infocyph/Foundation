<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session;

/** Mutable browser-session state owned by one InterMix execution scope. */
final class SessionExecutionState
{
    /** @var list<BrowserSession> */
    public array $active = [];

    public ?SessionStoreInterface $store = null;

    public function reset(bool $throw = true): void
    {
        $active = array_reverse($this->active);
        $this->active = [];
        $this->store = null;
        $failure = null;

        foreach ($active as $session) {
            try {
                $session->release();
            } catch (\Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if ($throw && $failure !== null) {
            throw $failure;
        }
    }
}
