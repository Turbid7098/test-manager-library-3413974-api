<?php

namespace Application\Ports;

interface IdGenerator
{
    public function generate(): string;
}
