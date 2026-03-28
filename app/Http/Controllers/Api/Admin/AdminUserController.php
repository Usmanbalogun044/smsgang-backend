<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

class AdminUserController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $query = User::query();

        if (request()->has('search')) {
            $search = request('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (request()->has('role')) {
            $query->where('role', request('role'));
        }

        return UserResource::collection($query->latest()->paginate(50));
    }

    public function stats()
    {
        return response()->json([
            'total_users' => User::count(),
            'new_this_month' => User::where('created_at', '>=', now()->startOfMonth())->count(),
            'admins' => User::where('role', 'admin')->count(),
            'suspended' => User::where('status', 'suspended')->count(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $user->update($request->validated());

        Log::channel('activity')->info('Admin updated user', [
            'user_id' => $user->id,
            'changes' => $request->validated(),
        ]);

        return new UserResource($user);
    }
}
