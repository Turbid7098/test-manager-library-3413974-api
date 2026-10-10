<?php

namespace App\Adapters\Outbound\Persistence;

use Application\Ports\TransactionManager;
use Illuminate\Support\Facades\DB;

final class DatabaseTransactionManager implements TransactionManager
{
    public function execute(callable $callback): mixed
    {
        return DB::transaction($callback);
    }
}
