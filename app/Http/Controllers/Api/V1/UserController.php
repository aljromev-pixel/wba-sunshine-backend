<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreUserRequest;
use App\Http\Requests\Api\V1\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManagement($request);

        return UserResource::collection(User::query()->orderBy('name')->get())->response();
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = DB::transaction(function () use ($data, $request): User {
            $user = User::query()->create([
                'name' => $data['fullName'],
                'first_name' => $data['name'],
                'last_name' => $data['lastName'] ?? null,
                'email' => $data['email'],
                'password' => $data['password'],
                'department' => $data['department'],
                'role_level' => $data['roleLevel'],
                'is_active' => $data['isActive'] ?? true,
                'must_change_password' => true,
            ]);
            $this->audit($request, 'User created', $user);

            return $user;
        });

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();
        if ($user->is($request->user()) && array_key_exists('isActive', $data) && ! $data['isActive']) {
            throw ValidationException::withMessages(['isActive' => ['You cannot disable your own account.']]);
        }
        $attributes = [
            'name' => $data['fullName'],
            'first_name' => $data['name'],
            'last_name' => $data['lastName'] ?? null,
            'email' => $data['email'],
            'department' => $data['department'],
            'role_level' => $data['roleLevel'],
            'is_active' => $data['isActive'] ?? $user->is_active,
        ];

        if (! empty($data['password'])) {
            $attributes['password'] = $data['password'];
            $attributes['must_change_password'] = ! $user->is($request->user());
        }

        DB::transaction(function () use ($user, $attributes, $request, $data): void {
            $administrators = User::query()->where('department', 'Administration')->where('role_level', 'Manager')->where('is_active', true)->orderBy('id')->lockForUpdate()->get();
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $keepsAdminAccess = $attributes['department'] === 'Administration' && $attributes['role_level'] === 'Manager' && $attributes['is_active'];
            if ($administrators->contains('id', $user->id) && ! $keepsAdminAccess && $administrators->count() === 1) {
                throw ValidationException::withMessages(['roleLevel' => ['At least one active administrator must remain. Create another administrator before changing your role.']]);
            }
            $user->update($attributes);
            if (! empty($data['password']) || ! $user->is_active) {
                $tokens = $user->tokens();
                $currentToken = $request->user()->currentAccessToken();
                if ($user->is_active && $user->is($request->user()) && $currentToken instanceof PersonalAccessToken) {
                    $tokens->where('id', '!=', $currentToken->getKey());
                }
                $tokens->delete();
            }
            $this->audit($request, 'User updated', $user);
            if ($user->wasChanged('is_active')) {
                $this->audit($request, $user->is_active ? 'User enabled' : 'User disabled', $user);
            }
        });

        return (new UserResource($user->fresh()))->response();
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorizeManagement($request);

        abort_if($user->department === 'Administration' && $user->role_level === 'Manager' && ! $user->is($request->user()), 403);

        if ($user->is($request->user())) {
            throw ValidationException::withMessages([
                'user' => ['You cannot delete your own account.'],
            ]);
        }

        if ($user->inventoryMovements()->exists() || $user->requestedInventoryAdjustments()->exists() || $user->reviewedInventoryAdjustments()->exists()) {
            throw ValidationException::withMessages([
                'user' => ['Users with inventory history cannot be deleted.'],
            ]);
        }

        DB::transaction(function () use ($request, $user): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($user->inventoryMovements()->exists() || $user->requestedInventoryAdjustments()->exists() || $user->reviewedInventoryAdjustments()->exists()) {
                throw ValidationException::withMessages(['user' => ['Users with inventory history cannot be deleted.']]);
            }
            $this->audit($request, 'User deleted', $user);
            $user->tokens()->delete();
            $user->forceFill(['is_active' => false, 'deleted_by' => $request->user()->id])->save();
            $user->delete();
        });

        return response()->json(status: 204);
    }

    private function authorizeManagement(Request $request): void
    {
        abort_unless(
            $request->user()?->department === 'Administration' && $request->user()?->role_level === 'Manager',
            403,
        );
    }

    private function audit(Request $request, string $action, User $user): void
    {
        AuditLog::query()->create([
            'user_id' => $request->user()->id,
            'department' => $request->user()->department,
            'role_level' => $request->user()->role_level,
            'action' => $action,
            'module' => 'Users',
            'reference' => (string) $user->id,
            'description' => "{$user->name} ({$user->email})",
        ]);
    }
}
