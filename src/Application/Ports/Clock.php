<?php

namespace Application\Ports;

interface Clock
{
    /** Returns the current instant in UTC. */
    public function now(): \DateTimeImmutable;
}
