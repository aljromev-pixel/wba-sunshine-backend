<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\SupabaseAuth;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

#[Signature('app:import-supabase-users {--execute : Create or resume identities; default is dry-run} {--backup-confirmed : Confirm an external database backup and restore rehearsal} {--confirm-managed-emails : Confirm these administrator-assigned email addresses belong to their employees} {--finalize : Require Supabase mode, remove local password hashes and revoke old application sessions}')]
#[Description('Preserve warehouse user IDs and passwords while importing accounts through the Supabase Auth Admin API')]
class ImportSupabaseUsers extends Command
{
    public function handle(SupabaseAuth $provider): int
    {
        if (! Schema::hasColumn('users', 'auth_user_id')) {
            $this->error('Run the staged schema migration first. No account was imported.');

            return self::FAILURE;
        }
        $users = User::withTrashed()->orderBy('id')->get();
        foreach ($users as $user) {
            if (! in_array($user->role_level, User::ROLE_LEVELS_BY_DEPARTMENT[$user->department] ?? [], true)) {
                $this->error('An unsupported job role requires review before import.');

                return self::FAILURE;
            }
            if (! $user->auth_user_id && ! in_array(password_get_info((string) $user->getRawOriginal('password'))['algoName'], ['bcrypt', 'argon2id', 'argon2i'], true)) {
                $this->error('An unmapped profile has no compatible password hash. No passwords were invented.');

                return self::FAILURE;
            }
        }
        $this->info('Profiles: '.$users->count().'; Admin: '.$users->filter(fn (User $user): bool => $user->loginPortal() === 'admin')->count().'; Staff: '.$users->filter(fn (User $user): bool => $user->loginPortal() === 'staff')->count().'. Passwords and inventory links are preserved.');
        if (! $this->option('execute')) {
            $this->info('Dry-run only. No Auth API requests or database writes performed.');

            return self::SUCCESS;
        }
        if (! $this->option('backup-confirmed') || ! $this->option('confirm-managed-emails')) {
            $this->error('Execution requires an external backup/restore confirmation and verified ownership of the administrator-assigned email addresses.');

            return self::FAILURE;
        }
        if ($this->option('finalize') && ! $provider->enabled()) {
            $this->error('Finalization requires APP_AUTH_PROVIDER=supabase. No legacy hashes were removed.');

            return self::FAILURE;
        }
        foreach ($users as $user) {
            try {
                $id = $user->auth_user_id ?? $provider->importId($user);
                $identity = $provider->account($id);
                if ($identity === null) {
                    if ($user->auth_user_id) {
                        throw new \RuntimeException('An existing linked identity is missing; review rather than recreating it.');
                    }
                    $identity = $provider->createAccount($user, importHash: true);
                }
                if ($identity['id'] !== $id || strtolower(trim($identity['email'] ?? '')) !== $user->email || (string) ($identity['app_metadata']['wba_legacy_user_id'] ?? '') !== (string) $user->id) {
                    throw new \RuntimeException('The identity does not match this trusted import. No account was linked.');
                }
                DB::transaction(function () use ($user, $id): void {
                    $profile = User::withTrashed()->lockForUpdate()->findOrFail($user->id);
                    if ($profile->auth_user_id === $id) {
                        return;
                    }
                    if ($profile->auth_user_id !== null) {
                        throw new \RuntimeException('A conflicting identity mapping requires review.');
                    }
                    $profile->forceFill(['auth_user_id' => $id])->save();
                    AuditLog::query()->create([
                        'action' => 'Supabase identity linked', 'module' => 'Users', 'reference' => (string) $profile->id,
                        'description' => 'Trusted password-hash import linked the shared Auth identity; inventory/audit IDs and job permissions were preserved.',
                    ]);
                });
            } catch (Throwable) {
                $this->error('Import stopped at profile '.$user->id.'. Check provider configuration or an account collision. A rerun resumes only identities with matching trusted provenance; no secrets are logged.');

                return self::FAILURE;
            }
        }
        if ($this->option('finalize')) {
            DB::transaction(function (): void {
                if (User::withTrashed()->whereNull('auth_user_id')->exists()) {
                    throw new \RuntimeException('All profiles must be linked before finalization.');
                }
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement("SELECT set_config('wba.confirm_auth_cutover', 'yes', true)");
                    DB::statement('SELECT public.wba_finalize_auth_migration()');

                    return;
                }
                User::withTrashed()->update(['password' => null]);
                DB::table('personal_access_tokens')->delete();
                AuditLog::query()->create([
                    'action' => 'Supabase authentication finalized', 'module' => 'Users',
                    'description' => 'All profiles are linked. Legacy password copies and pre-cutover application sessions were removed after explicit migration confirmation.',
                ]);
            });
        }
        $this->info('Import complete. Sign in through the correct portal and verify hosted workflows before finalizing the cutover.');

        return self::SUCCESS;
    }
}
