<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SecurityMonitorService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/health — chequeo público para balanceadores y monitores externos: solo estados, sin detalles internos.
 */
class HealthController extends Controller
{
    public function __invoke(SecurityMonitorService $monitor): JsonResponse
    {
        $health = $monitor->health();

        return response()->json([
            'status' => $health['status'],
            'checks' => array_map(fn ($check) => $check['ok'] ? 'ok' : 'fail', $health['checks']),
        ], $health['checks']['database']['ok'] && $health['checks']['storage']['ok'] ? 200 : 503);
    }
}
