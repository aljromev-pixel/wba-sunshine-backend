<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryNotificationResource;
use App\Support\InventoryUpdates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return InventoryNotificationResource::collection(InventoryUpdates::notifications($request->user())->orderByDesc('created_at')->orderByDesc('id')->paginate(25))
            ->additional(['unreadCount' => InventoryUpdates::notifications($request->user())->whereNull('read_at')->count()])->response();
    }

    public function read(Request $request, string $id): JsonResponse
    {
        InventoryUpdates::notifications($request->user())->findOrFail($id)->markAsRead();

        return response()->json(['unreadCount' => InventoryUpdates::notifications($request->user())->whereNull('read_at')->count()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        InventoryUpdates::notifications($request->user())->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['unreadCount' => 0]);
    }
}
