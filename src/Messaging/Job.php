<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Messaging;

/**
 * Marks an application message as queued/synchronous work eligible for
 * Foundation job middleware. Handler resolution remains messaging-configured.
 */
interface Job {}
