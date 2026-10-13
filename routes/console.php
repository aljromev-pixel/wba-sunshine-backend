<?php

use App\Models\User;
use App\Support\SupabaseAuth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:bootstrap-admin', function (): int {
    $data = config('services.bootstrap_admin');
    $data['email'] = strtolower(trim($data['email'] ?? ''));
    $validator = Validator::make($data, [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'max:255'],
        'password' => ['required', 'string', 'min:12'],
    ]);

    if ($validator->fails()) {
        $this->error('Set BOOTSTRAP_ADMIN_NAME, a valid BOOTSTRAP_ADMIN_EMAIL and BOOTSTRAP_ADMIN_PASSWORD (at least 12 characters).');

        return 1;
    }

    $existing = User::query()->where('email', $data['email'])->first();
    if ($existing !== null) {
        if ($existing->department !== 'Administration' || $existing->role_level !== 'Manager') {
            $this->error('This email already belongs to a non-administrator. No account was changed.');

            return 1;
        }
        $this->info('Administrator already exists. No credentials were changed.');

        return 0;
    }

    if (User::query()->where('department', 'Administration')->where('role_level', 'Manager')->exists()) {
        $this->error('An administrator already exists. Use user management to add accounts.');

        return 1;
    }

    DB::transaction(function () use ($data): void {
        $provider = app(SupabaseAuth::class);
        $user = User::query()->create([
            ...$data,
            'password' => $provider->enabled() ? null : $data['password'],
            'department' => 'Administration',
            'role_level' => 'Manager',
        ]);
        if ($provider->enabled()) {
            $identity = $provider->createAccount($user, $data['password']);
            $user->forceFill(['auth_user_id' => $identity['id']])->save();
        }
    });
    $this->info('Initial administrator created. Remove bootstrap credentials from Render now.');

    return 0;
})->purpose('Create the initial deployment administrator without demo credentials');
