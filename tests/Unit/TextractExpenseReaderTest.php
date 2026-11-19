<?php

namespace Tests\Unit;

use App\Services\TextractExpenseReader;
use Aws\Textract\TextractClient;
use Tests\TestCase;

class TextractExpenseReaderTest extends TestCase
{
    public function test_reads_chilean_thousands_and_dot_decimals(): void
    {
        $line = fn (string $item, string $qty, string $price) => ['LineItemExpenseFields' => [
            ['Type' => ['Text' => 'ITEM'], 'ValueDetection' => ['Text' => $item]],
            ['Type' => ['Text' => 'QUANTITY'], 'ValueDetection' => ['Text' => $qty]],
            ['Type' => ['Text' => 'UNIT_PRICE'], 'ValueDetection' => ['Text' => $price]],
        ]];

        // Valores reales de pruebas-facturas/prueba3 y casos del formato chileno.
        $this->app->instance(TextractClient::class, new class(['ExpenseDocuments' => [['LineItemGroups' => [['LineItems' => [$line('GUANTES', '50.00', '4.632'), $line('CINTA', '4.00', '$ 3.468'), $line('NOTEBOOK', '1', '1.250.000'), $line('CABLE', '2,00', '1.250,50')]]]]]])
        {
            public function __construct(private array $result) {}

            public function analyzeExpense(array $args): array
            {
                return $this->result;
            }
        });

        $items = app(TextractExpenseReader::class)->read('bytes')['items'];

        $this->assertSame(
            [['GUANTES', 50, 4632.0], ['CINTA', 4, 3468.0], ['NOTEBOOK', 1, 1250000.0], ['CABLE', 2, 1250.5]],
            array_map(fn ($i) => [$i['name'], $i['quantity'], $i['unit_price']], $items),
        );
    }
}
