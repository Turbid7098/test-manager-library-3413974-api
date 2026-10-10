<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE library.book ADD COLUMN version bigint NOT NULL DEFAULT 0;');
        DB::statement('ALTER TABLE library.book ALTER COLUMN version DROP DEFAULT;');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE library.book DROP COLUMN version;');
    }
};
