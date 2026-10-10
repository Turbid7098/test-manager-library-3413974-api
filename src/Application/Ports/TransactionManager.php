<?php

namespace Application\Ports;

interface TransactionManager
{
    /**
     * Executes the given callback within a database transaction.
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function execute(callable $callback): mixed;
}
