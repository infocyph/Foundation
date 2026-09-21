<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks\Support;

final readonly class Phase0Node
{
    public function __construct(public Phase0Leaf $leaf) {}
}
