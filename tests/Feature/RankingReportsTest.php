<?php

namespace Tests\Feature;

use App\Models\AnnualBudget;
use App\Models\Career;
use App\Models\EquipmentUnit;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Storeroom;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

/**
 * Rankings del centro de reportes (docs/reportes-rankings.md): `GET /api/reports/top-suppliers` y `/top-teachers`.
 */
class RankingReportsTest extends TestCase
{
    use DatabaseTransactions;
    use ReadsXlsx;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function actingAsRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['role' => $role, 'is_active' => true, ...$attributes]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function purchase(Supplier $supplier, float $total, string $createdAt, array $attributes = []): Purchase
    {
        $purchase = Purchase::factory()->create(['supplier_id' => $supplier->id, 'total' => $total, ...$attributes]);
        $purchase->forceFill(['created_at' => $createdAt])->save();

        return $purchase;
    }

    public function test_suppliers_ranked_by_amount_without_cancelled_with_ties_and_top(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);
        $big = Supplier::factory()->create(['name' => 'Zeta Grande']);
        $tieA = Supplier::factory()->create(['name' => 'Alfa Empate']);
        $tieB = Supplier::factory()->create(['name' => 'Beta Empate']);
        $outside = Supplier::factory()->create(['name' => 'Fuera del período']);

        $first = $this->purchase($big, 300000, '2036-03-10 10:00:00', ['status' => Purchase::STATUS_COMPLETED]);
        PurchaseItem::create(['purchase_id' => $first->id, 'name' => 'Cable', 'quantity' => 7, 'unit_price' => 1000]);
        $this->purchase($big, 100000, '2036-04-02 10:00:00');
        $this->purchase($big, 999999, '2036-04-03 10:00:00', ['status' => Purchase::STATUS_CANCELLED]);
        $this->purchase($tieA, 50000, '2036-05-01 10:00:00');
        $this->purchase($tieB, 50000, '2036-05-02 10:00:00');
        $this->purchase($outside, 900000, '2036-08-01 10:00:00');

        $response = $this->get('/api/reports/top-suppliers?from=2036-01-01&to=2036-06-30')->assertOk();
        $this->assertMatchesRegularExpression('/filename="proveedores-mas-compras-\d{8}-\d{6}\.xlsx"/', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(['Ranking', 'Órdenes', 'Info'], array_keys($this->readXlsx($response)));

        $ranking = $this->xlsxRecords($response, 'Ranking');
        $this->assertSame(['Zeta Grande', 'Alfa Empate', 'Beta Empate'], array_column($ranking, 'Proveedor'), 'por monto, el empate por nombre, sin el de fuera del período');
        $this->assertSame([1, 2, 2], array_column($ranking, 'Puesto'));
        $this->assertSame(400000, $ranking[0]['Monto total'], 'la orden cancelada no suma');
        $this->assertSame(2, $ranking[0]['Órdenes']);
        $this->assertSame(1, $ranking[0]['Recibidas']);
        $this->assertSame(7, $ranking[0]['Unidades']);
        $this->assertSame(200000, $ranking[0]['Promedio por orden']);
        $this->assertCount(4, $this->xlsxRecords($response, 'Órdenes'));

        $json = $this->getJson('/api/reports/top-suppliers?from=2036-01-01&to=2036-06-30&top=1&preview=1')->assertOk()->json();
        $sheet = collect($json['sheets'])->firstWhere('name', 'Ranking');
        $this->assertSame([[1, 'Zeta Grande']], array_map(fn ($r) => array_slice($r, 0, 2), $sheet['rows']));
        $this->assertCount(2, collect($json['sheets'])->firstWhere('name', 'Órdenes')['rows'], 'el detalle solo del ranking');
        $this->assertStringContainsString('Mostrar: los primeros 1', (string) collect($json['info'])->firstWhere(0, 'Filtros')[1]);
    }

    public function test_suppliers_by_budget_career_validation_and_roles(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);
        $career = Career::factory()->create();
        $budget = AnnualBudget::factory()->create(['career_id' => $career->id]);
        $mine = Supplier::factory()->create(['name' => 'De la carrera']);
        $other = Supplier::factory()->create(['name' => 'De otra carrera']);
        $this->purchase($mine, 1000, '2036-03-10 10:00:00', ['annual_budget_id' => $budget->id]);
        $this->purchase($other, 5000, '2036-03-10 10:00:00', ['annual_budget_id' => AnnualBudget::factory()->create()->id]);

        $json = $this->getJson("/api/reports/top-suppliers?from=2036-01-01&to=2036-06-30&career_id={$career->id}&preview=1")->assertOk()->json();
        $this->assertSame(['De la carrera'], array_column(collect($json['sheets'])->firstWhere('name', 'Ranking')['rows'], 1));

        $this->getJson('/api/reports/top-suppliers')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->getJson('/api/reports/top-suppliers?from=2036-01-01&to=2036-06-30&top=0')->assertUnprocessable()->assertJsonValidationErrors('top');

        foreach ([User::ROLE_WAREHOUSE, User::ROLE_ADMIN, User::ROLE_TEACHER] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/reports/top-suppliers?from=2036-01-01&to=2036-06-30')->assertForbidden();
        }
    }

    public function test_teachers_ranked_by_loans_with_rejected_apart_units_and_type(): void
    {
        $this->actingAsRole(User::ROLE_DIRECTOR);
        $career = Career::factory()->create(['name' => 'Carrera del ranking']);
        $ana = User::factory()->create(['role' => User::ROLE_TEACHER, 'name' => 'Ana Profesora']);
        $beto = User::factory()->create(['role' => User::ROLE_TEACHER, 'name' => 'Beto Profesor']);
        $pan = User::factory()->create(['role' => User::ROLE_WAREHOUSE]);

        $product = Product::factory()->create(['type' => Product::TYPE_EQUIPMENT]);
        $loan = Loan::factory()->processed()->create(['requested_by' => $ana->id, 'career_id' => $career->id, 'loan_date' => '2036-03-10', 'subject' => 'Redes', 'type' => Loan::TYPE_DIRECT]);
        $item = LoanItem::create(['loan_id' => $loan->id, 'product_id' => $product->id, 'name' => $product->name, 'quantity' => 2]);
        $item->units()->attach(EquipmentUnit::factory()->count(2)->create(['product_id' => $product->id])->pluck('id'));
        Loan::factory()->create(['requested_by' => $ana->id, 'loan_date' => '2036-03-12', 'subject' => 'Bases de datos']);
        Loan::factory()->rejected()->create(['requested_by' => $ana->id, 'loan_date' => '2036-03-13']);
        Loan::factory()->create(['requested_by' => $beto->id, 'loan_date' => '2036-04-01']);
        Loan::factory()->rejected()->create(['requested_by' => $beto->id, 'loan_date' => '2036-04-02']);
        Loan::factory()->rejected()->create(['requested_by' => $beto->id, 'loan_date' => '2036-04-03']);
        Loan::factory()->create(['requested_by' => $beto->id, 'loan_date' => '2036-09-01']);
        Loan::factory()->create(['requested_by' => $pan->id, 'loan_date' => '2036-03-10']);

        $response = $this->get('/api/reports/top-teachers?from=2036-01-01&to=2036-06-30')->assertOk();
        $this->assertSame(['Ranking', 'Préstamos', 'Info'], array_keys($this->readXlsx($response)));
        $ranking = $this->xlsxRecords($response, 'Ranking');
        $this->assertSame(['Ana Profesora', 'Beto Profesor'], array_column($ranking, 'Profesor'), 'solo PRO-01 y solo el período');
        $this->assertSame([2, 1], array_column($ranking, 'Préstamos'), 'los rechazados no cuentan');
        $this->assertSame([1, 2], array_column($ranking, 'Rechazados'));
        $this->assertSame([1, 0], array_column($ranking, 'Presenciales'));
        $this->assertSame([2, 0], array_column($ranking, 'Unidades prestadas'));
        $this->assertSame('Bases de datos, Redes', $ranking[0]['Asignaturas']);
        $this->assertSame('Carrera del ranking', $ranking[0]['Carreras']);
        $this->assertCount(6, $this->xlsxRecords($response, 'Préstamos'));

        $json = $this->getJson('/api/reports/top-teachers?from=2036-01-01&to=2036-06-30&tipo=presencial&preview=1')->assertOk()->json();
        $this->assertSame(['Ana Profesora'], array_column(collect($json['sheets'])->firstWhere('name', 'Ranking')['rows'], 1));
        $this->assertStringContainsString('Tipo: Presencial', (string) collect($json['info'])->firstWhere(0, 'Filtros')[1]);
    }

    public function test_teachers_warehouse_scope_validation_and_roles(): void
    {
        $storeroom = Storeroom::factory()->create(['name' => 'Pañol del ranking']);
        $this->actingAsRole(User::ROLE_WAREHOUSE, ['storeroom_id' => $storeroom->id]);
        $mine = User::factory()->create(['role' => User::ROLE_TEACHER, 'name' => 'Profe de mi pañol']);
        $other = User::factory()->create(['role' => User::ROLE_TEACHER, 'name' => 'Profe de otro pañol']);
        Loan::factory()->create(['requested_by' => $mine->id, 'storeroom_id' => $storeroom->id, 'loan_date' => '2036-03-10']);
        Loan::factory()->create(['requested_by' => $other->id, 'storeroom_id' => Storeroom::factory()->create()->id, 'loan_date' => '2036-03-10']);

        $json = $this->getJson('/api/reports/top-teachers?from=2036-03-01&to=2036-03-31&preview=1')->assertOk()->json();
        $this->assertSame(['Profe de mi pañol'], array_column(collect($json['sheets'])->firstWhere('name', 'Ranking')['rows'], 1));
        $this->assertSame('Préstamos del pañol Pañol del ranking', collect($json['info'])->firstWhere(0, 'Alcance')[1]);

        $this->getJson('/api/reports/top-teachers')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->getJson('/api/reports/top-teachers?from=2036-03-01&to=2036-03-31&tipo=otro')->assertUnprocessable()->assertJsonValidationErrors('tipo');

        foreach ([User::ROLE_ADMIN, User::ROLE_TEACHER] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/reports/top-teachers?from=2036-03-01&to=2036-03-31')->assertForbidden();
        }
    }
}
