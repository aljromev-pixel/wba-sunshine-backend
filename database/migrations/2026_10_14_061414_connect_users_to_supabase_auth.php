<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'auth_user_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->uuid('auth_user_id')->nullable()->unique();
                $table->string('password')->nullable()->change();
            });
        }
        if (DB::getDriverName() === 'pgsql' && DB::selectOne("SELECT to_regclass('auth.users') IS NOT NULL AS present")->present) {
            DB::unprepared(file_get_contents(database_path('supabase_admin_staff_auth.sql')));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Identity migration preserves existing records and requires a reviewed forward migration to reverse.');
    }
};
