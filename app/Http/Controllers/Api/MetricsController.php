<?php

namespace App\Http\Controllers\Api;

use App\GraphQL\MetricsGraphQL;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MetricsController extends Controller
{
    /**
     * REQ-14: endpoint GraphQL del panel (FU-06, DIR-01/AD-01).
     *
     * POST /api/metrics  {query, variables?, operationName?}  →  {data, errors?}
     *
     * Siguiendo la convención de GraphQL, los errores de consulta o de
     * resolución viajan en `errors` con HTTP 200; la autenticación y los
     * roles se rechazan antes con 401/403.
     */
    public function __invoke(Request $request, MetricsGraphQL $graphql): JsonResponse
    {
        Gate::authorize('viewDashboard');

        $validated = $request->validate([
            'query' => ['required', 'string', 'max:10000'],
            'variables' => ['nullable', 'array'],
            'operationName' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($graphql->execute(
            $validated['query'],
            $validated['variables'] ?? null,
            $validated['operationName'] ?? null
        ));
    }
}
