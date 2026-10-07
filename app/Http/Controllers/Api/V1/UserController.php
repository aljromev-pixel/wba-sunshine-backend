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
use Illuminate\Validation\ValidationException;

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
        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'department' => $data['department'],
            'role_level' => $data['roleLevel'],
        ]);
        $this->audit($request, 'User created', $user);

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();
        $attributes = [
            'name' => $data['name'],
            'email' => $data['email'],
            'department' => $data['department'],
            'role_level' => $data['roleLevel'],
        ];

        if (! empty($data['password'])) {
            $attributes['password'] = $data['password'];
        }

        $user->update($attributes);
        $this->audit($request, 'User updated', $user);

        return (new UserResource($user->fresh()))->response();
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorizeManagement($request);

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

        $this->audit($request, 'User deleted', $user);
        $user->tokens()->delete();
        $user->delete();

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
