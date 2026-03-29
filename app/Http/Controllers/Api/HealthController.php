<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class HealthController extends Controller
{
    public function check()
    {
        try {
            // Test database connection
            DB::connection()->getPdo();

            return Response::json([
                'status' => 'healthy',
                'timestamp' => now()->toIso8601String(),
            ], 200);
        } catch (\Exception $e) {
            return Response::json([
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ], 503);
        }
    }
}
