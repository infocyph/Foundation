<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks\Support;

final readonly class Phase9DiNode
{
    public function __construct(public Phase9DiLeaf $leaf) {}
}
