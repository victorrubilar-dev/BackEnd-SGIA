<?php

namespace Tests\Feature;

use App\Jobs\CheckStockAlertJob;
use App\Models\Product;
use App\Models\StockAlert;
use App\Models\User;
use App\Notifications\StockThresholdNotification;
use App\Services\StockAlertService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockAlertTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected User $warehouse;

    protected User $director;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin_alert_'.uniqid().'@inacap.cl',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->warehouse = User::factory()->create([
            'email' => 'wh_alert_'.uniqid().'@inacap.cl',
            'role' => User::ROLE_WAREHOUSE,
            'is_active' => true,
        ]);

        $this->director = User::factory()->create([
            'email' => 'dir_alert_'.uniqid().'@inacap.cl',
            'role' => User::ROLE_DIRECTOR,
            'is_active' => true,
        ]);

        $this->teacher = User::factory()->create([
            'email' => 'teach_alert_'.uniqid().'@inacap.cl',
            'role' => User::ROLE_TEACHER,
            'is_active' => true,
        ]);
    }

    public function test_warning_alert_triggered_when_stock_reaches_minimo_plus_5(): void
    {
        Notification::fake();

        $product = Product::factory()->supply()->create([
            'quantity' => 20,
            'stock_minimo' => 10,
        ]);

        // Reduce stock to 15 (minimo + 5)
        Sanctum::actingAs($this->warehouse);
        $response = $this->patchJson('/api/products/'.$product->id, [
            'quantity' => 15,
        ]);
        $response->assertStatus(200);

        $this->assertDatabaseHas('stock_alerts', [
            'product_id' => $product->id,
            'alert_type' => StockAlert::TYPE_WARNING,
            'current_stock' => 15,
            'is_resolved' => false,
        ]);

        Notification::assertSentTo(
            [$this->admin, $this->director, $this->warehouse],
            StockThresholdNotification::class
        );

        Notification::assertNotSentTo(
            [$this->teacher],
            StockThresholdNotification::class
        );
    }

    public function test_critical_alert_triggered_when_stock_reaches_minimo(): void
    {
        Notification::fake();

        $product = Product::factory()->supply()->create([
            'quantity' => 15,
            'stock_minimo' => 10,
        ]);

        // Reduce stock to exactly 10 (or lower)
        Sanctum::actingAs($this->warehouse);
        $response = $this->patchJson('/api/products/'.$product->id, [
            'quantity' => 10,
        ]);
        $response->assertStatus(200);

        $this->assertDatabaseHas('stock_alerts', [
            'product_id' => $product->id,
            'alert_type' => StockAlert::TYPE_CRITICAL,
            'current_stock' => 10,
            'is_resolved' => false,
        ]);

        Notification::assertSentTo(
            [$this->admin, $this->director, $this->warehouse],
            StockThresholdNotification::class
        );
    }

    public function test_alerts_auto_resolve_when_stock_restocked_above_threshold(): void
    {
        $product = Product::factory()->supply()->create([
            'quantity' => 5,
            'stock_minimo' => 10,
        ]);

        // Create active critical alert
        StockAlert::create([
            'product_id' => $product->id,
            'alert_type' => StockAlert::TYPE_CRITICAL,
            'current_stock' => 5,
            'stock_minimo' => 10,
            'message' => 'Alerta critica',
            'is_resolved' => false,
        ]);

        // Restock to 20 (> 10 + 5)
        Sanctum::actingAs($this->warehouse);
        $this->patchJson('/api/products/'.$product->id, [
            'quantity' => 20,
        ])->assertStatus(200);

        $this->assertDatabaseHas('stock_alerts', [
            'product_id' => $product->id,
            'is_resolved' => true,
        ]);
    }

    public function test_check_stock_alert_job_runs_correctly(): void
    {
        $product = Product::factory()->supply()->create([
            'quantity' => 3,
            'stock_minimo' => 5,
        ]);

        $job = new CheckStockAlertJob($product);
        $job->handle(new StockAlertService);

        $this->assertDatabaseHas('stock_alerts', [
            'product_id' => $product->id,
            'alert_type' => StockAlert::TYPE_CRITICAL,
            'current_stock' => 3,
            'is_resolved' => false,
        ]);
    }

    public function test_can_list_critical_stock_alerts_endpoint(): void
    {
        Sanctum::actingAs($this->warehouse);

        $product = Product::factory()->supply()->create(['name' => 'Tester Multimetro', 'quantity' => 2, 'stock_minimo' => 5]);

        StockAlert::create([
            'product_id' => $product->id,
            'alert_type' => StockAlert::TYPE_CRITICAL,
            'current_stock' => 2,
            'stock_minimo' => 5,
            'message' => 'Stock crítico',
            'is_resolved' => false,
        ]);

        $response = $this->getJson('/api/alerts/critical-stock');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'product_id', 'product_name', 'alert_type', 'current_stock', 'stock_minimo', 'message'],
            ],
        ]);
        $response->assertJsonFragment(['product_name' => 'Tester Multimetro']);
    }

    public function test_listing_matches_the_real_inventory_stock(): void
    {
        Sanctum::actingAs($this->warehouse);

        $supply = fn (string $name, int $quantity, array $extra = []) => Product::factory()->supply()
            ->create(['name' => $name, 'quantity' => $quantity, 'stock_minimo' => 5] + $extra);
        $alert = fn (Product $product, string $type, int $stock) => StockAlert::create([
            'product_id' => $product->id, 'alert_type' => $type, 'current_stock' => $stock,
            'stock_minimo' => 5, 'message' => 'x', 'is_resolved' => false,
        ]);

        // Crítico real sin alerta guardada: debe aparecer.
        $missing = $supply('Sin alerta', 1);
        // Alerta crítica con cifras viejas y una advertencia duplicada: queda una sola, al día.
        $stale = $supply('Cifras viejas', 4);
        $alert($stale, StockAlert::TYPE_CRITICAL, 0);
        $alert($stale, StockAlert::TYPE_WARNING, 9);
        // Era crítico y hoy solo es advertencia: cambia de tipo.
        $improved = $supply('Mejoró', 8);
        $alert($improved, StockAlert::TYPE_CRITICAL, 2);
        // Stock repuesto y producto inactivo: sus alertas se cierran.
        $restocked = $supply('Repuesto', 40);
        $alert($restocked, StockAlert::TYPE_CRITICAL, 1);
        $inactive = $supply('Inactivo', 0, ['is_active' => false]);
        $alert($inactive, StockAlert::TYPE_CRITICAL, 0);

        $response = $this->getJson('/api/alerts/critical-stock?per_page=100')->assertOk();
        $rows = collect($response->json('data'))->keyBy('product_id');

        $this->assertSame(['critical', 1, 5], [$rows[$missing->id]['alert_type'], $rows[$missing->id]['current_stock'], $rows[$missing->id]['stock_minimo']]);
        $this->assertSame(['critical', 4], [$rows[$stale->id]['alert_type'], $rows[$stale->id]['current_stock']]);
        $this->assertStringContainsString('(4/5)', $rows[$stale->id]['message']);
        $this->assertSame(['warning', 8], [$rows[$improved->id]['alert_type'], $rows[$improved->id]['current_stock']]);
        $this->assertFalse($rows->has($restocked->id));
        $this->assertFalse($rows->has($inactive->id));
        $this->assertSame(1, StockAlert::where('product_id', $stale->id)->where('is_resolved', false)->count());

        // Los totales salen del inventario, no de la página pedida.
        $counts = $this->getJson('/api/alerts/critical-stock?per_page=1')->assertOk()->json('counts');
        $this->assertSame(Product::where('is_active', true)->whereColumn('quantity', '<=', 'stock_minimo')->count(), $counts['critical']);
        $this->assertSame(
            Product::where('is_active', true)->whereColumn('quantity', '>', 'stock_minimo')->whereRaw('quantity <= stock_minimo + 5')->count(),
            $counts['warning']
        );
        $this->assertSame($counts['critical'] + $counts['warning'], $counts['active']);
    }

    public function test_manually_resolved_alert_stays_closed_until_the_stock_changes(): void
    {
        Sanctum::actingAs($this->warehouse);

        $product = Product::factory()->supply()->create(['quantity' => 3, 'stock_minimo' => 5]);
        $open = fn () => collect($this->getJson('/api/alerts/critical-stock?per_page=100')->json('data'))->firstWhere('product_id', $product->id);

        $alert = $open();
        $this->assertSame('critical', $alert['alert_type']);

        // Atendida a mano: no reaparece aunque el producto siga en crítico.
        $this->patchJson("/api/alerts/{$alert['id']}/resolve")->assertOk();
        $this->assertNull($open());

        // El stock cambia y sigue en crítico: alerta nueva con las cifras actuales.
        $product->update(['quantity' => 2]);
        $this->assertSame(2, $open()['current_stock']);
        $this->assertNotSame($alert['id'], $open()['id']);
    }

    public function test_can_resolve_stock_alert_endpoint(): void
    {
        Sanctum::actingAs($this->warehouse);

        $product = Product::factory()->supply()->create();

        $alert = StockAlert::create([
            'product_id' => $product->id,
            'alert_type' => StockAlert::TYPE_WARNING,
            'current_stock' => 8,
            'stock_minimo' => 5,
            'message' => 'Advertencia stock',
            'is_resolved' => false,
        ]);

        $response = $this->patchJson('/api/alerts/'.$alert->id.'/resolve');

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Alerta marcada como resuelta exitosamente.',
            'alert' => [
                'id' => $alert->id,
                'is_resolved' => true,
            ],
        ]);

        $alert->refresh();
        $this->assertTrue($alert->is_resolved);
    }

    public function test_teacher_cannot_list_or_resolve_stock_alerts(): void
    {
        Sanctum::actingAs($this->teacher);

        $alert = StockAlert::create([
            'product_id' => Product::factory()->supply()->create()->id,
            'alert_type' => StockAlert::TYPE_WARNING,
            'current_stock' => 8,
            'stock_minimo' => 5,
            'message' => 'Advertencia stock',
            'is_resolved' => false,
        ]);

        $this->getJson('/api/alerts/critical-stock')->assertForbidden();
        $this->patchJson('/api/alerts/'.$alert->id.'/resolve')->assertForbidden();

        $this->assertFalse($alert->fresh()->is_resolved);
    }

    public function test_director_can_list_and_resolve_stock_alerts(): void
    {
        $director = User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]);
        Sanctum::actingAs($director);

        $alert = StockAlert::create([
            'product_id' => Product::factory()->supply()->create()->id,
            'alert_type' => StockAlert::TYPE_WARNING,
            'current_stock' => 8,
            'stock_minimo' => 5,
            'message' => 'Advertencia stock',
            'is_resolved' => false,
        ]);

        $this->getJson('/api/alerts/critical-stock')->assertOk();
        $this->patchJson('/api/alerts/'.$alert->id.'/resolve')->assertOk();
        $this->assertTrue($alert->fresh()->is_resolved);
    }
}
