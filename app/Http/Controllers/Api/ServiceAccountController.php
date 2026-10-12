<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Lets an automated caller confirm its API key works and see what it may do.
 */
class ServiceAccountController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $account = $request->user();

        return response()->json([
            'account' => $account->name,
            'permissions' => $account->getAllPermissions()->pluck('name')->sort()->values(),
        ]);
    }
}
