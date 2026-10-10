<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE library.book ADD CONSTRAINT ck_book_total_positive CHECK (total_copies >= 1);');
        DB::statement("ALTER TABLE library.\"user\" ADD CONSTRAINT ck_user_role CHECK (role IN ('admin', 'librarian'));");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE library.book DROP CONSTRAINT ck_book_total_positive;');
        DB::statement('ALTER TABLE library."user" DROP CONSTRAINT ck_user_role;');
    }
};
