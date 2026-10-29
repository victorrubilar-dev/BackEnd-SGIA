<?php

namespace App\GraphQL;

use App\Services\MetricsService;
use GraphQL\Error\DebugFlag;
use GraphQL\Error\Error;
use GraphQL\GraphQL;
use GraphQL\Utils\BuildSchema;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\DisableIntrospection;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;

/**
 * Esquema GraphQL del panel (`POST /api/metrics`). Es el contrato que consume
 * `packages/api-client/src/services/metrics.ts` del frontend.
 */
class MetricsGraphQL
{
    public const SDL = <<<'GRAPHQL'
        enum MetricEntity { EQUIPMENT SUPPLIES LOANS ALERTS MAINTENANCE CAREERS TEACHERS }
        enum MetricAggregation { TOTAL AVERAGE COUNT RATE }
        enum TimeRange { LAST_7_DAYS LAST_30_DAYS SEMESTER_CURRENT SEMESTER_PREVIOUS YEAR_TO_DATE CUSTOM }
        enum ChartType { BAR LINE DOUGHNUT POLAR_AREA }

        input MetricQueryInput {
          entity: MetricEntity!
          metric: MetricAggregation!
          timeRange: TimeRange!
          chartType: ChartType!
          title: String
          groupBy: String
          dateFrom: String
          dateTo: String
          limit: Int
        }

        type MetricDataPoint {
          label: String!
          value: Float!
          secondaryValue: Float
          category: String
        }

        type MetricResult {
          entity: MetricEntity!
          metric: MetricAggregation!
          timeRange: TimeRange!
          chartType: ChartType!
          points: [MetricDataPoint!]!
          summary: String
        }

        type ExecutiveKpis {
          activosDisponibles: Int!
          activosEnPrestamo: Int!
          activosEnTaller: Int!
          activosDeBaja: Int!
          prestamosActivosHoy: Int!
          tasaAtrasosPct: Float!
          alertasCriticasNoResueltas: Int!
          tasaRotacionMensualPct: Float!
        }

        type Query {
          executiveKpis(timeRange: TimeRange): ExecutiveKpis!
          customMetric(input: MetricQueryInput!): MetricResult!
        }
        GRAPHQL;

    public function __construct(private readonly MetricsService $metrics) {}

    /**
     * @param  array<string, mixed>|null  $variables
     * @return array{data?: array<string, mixed>|null, errors?: array<int, array<string, mixed>>}
     */
    public function execute(string $query, ?array $variables = null, ?string $operationName = null): array
    {
        $root = [
            'executiveKpis' => fn ($root, array $args) => $this->metrics->executiveKpis($args['timeRange'] ?? 'LAST_30_DAYS'),
            'customMetric' => fn ($root, array $args) => $this->metrics->customMetric($args['input']),
        ];

        // Límites de profundidad y complejidad frente a consultas abusivas; sin introspección en producción.
        $rules = array_merge(DocumentValidator::defaultRules(), [
            new QueryDepth(6),
            new QueryComplexity(200),
        ]);

        if (app()->isProduction()) {
            $rules[] = new DisableIntrospection(DisableIntrospection::ENABLED);
        }

        $result = GraphQL::executeQuery(
            BuildSchema::build(self::SDL),
            $query,
            $root,
            null,
            $variables,
            $operationName,
            null,
            $rules
        );

        // Los errores inesperados se registran y el cliente solo ve "Internal server error".
        $result->setErrorsHandler(function (array $errors, callable $formatter) {
            foreach ($errors as $error) {
                if ($error instanceof Error && ! $error->isClientSafe()) {
                    report($error->getPrevious() ?? $error);
                }
            }

            return array_map($formatter, $errors);
        });

        return $result->toArray(DebugFlag::NONE);
    }
}
