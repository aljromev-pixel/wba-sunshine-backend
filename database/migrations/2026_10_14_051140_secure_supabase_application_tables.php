<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'anon') AS present")->present) {
            return;
        }
        DB::unprepared(file_get_contents(database_path('supabase_security.sql')));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            throw new RuntimeException('Supabase security hardening cannot be automatically rolled back to public access.');
        }
    }
};
