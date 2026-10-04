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
    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin_alert_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->warehouse = User::factory()->create([
            'email' => 'wh_alert_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_WAREHOUSE,
            'is_active' => true,
        ]);

        $this->teacher = User::factory()->create([
            'email' => 'teach_alert_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_TEACHER,
            'is_active' => true,
        ]);
    }

    public function test_warning_alert_triggered_when_stock_reaches_minimo_plus_5(): void
    {
        Notification::fake();

        $product = Product::factory()->create([
            'quantity' => 20,
            'stock_minimo' => 10,
        ]);

        // Reduce stock to 15 (minimo + 5)
        Sanctum::actingAs($this->warehouse);
        $response = $this->patchJson('/api/products/' . $product->id, [
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
            [$this->admin, $this->warehouse],
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

        $product = Product::factory()->create([
            'quantity' => 15,
            'stock_minimo' => 10,
        ]);

        // Reduce stock to exactly 10 (or lower)
        Sanctum::actingAs($this->warehouse);
        $response = $this->patchJson('/api/products/' . $product->id, [
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
            [$this->admin, $this->warehouse],
            StockThresholdNotification::class
        );
    }

    public function test_alerts_auto_resolve_when_stock_restocked_above_threshold(): void
    {
        $product = Product::factory()->create([
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
        $this->patchJson('/api/products/' . $product->id, [
            'quantity' => 20,
        ])->assertStatus(200);

        $this->assertDatabaseHas('stock_alerts', [
            'product_id' => $product->id,
            'is_resolved' => true,
        ]);
    }

    public function test_check_stock_alert_job_runs_correctly(): void
    {
        $product = Product::factory()->create([
            'quantity' => 3,
            'stock_minimo' => 5,
        ]);

        $job = new CheckStockAlertJob($product);
        $job->handle(new StockAlertService());

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

        $product = Product::factory()->create(['name' => 'Tester Multimetro']);

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

    public function test_can_resolve_stock_alert_endpoint(): void
    {
        Sanctum::actingAs($this->warehouse);

        $product = Product::factory()->create();

        $alert = StockAlert::create([
            'product_id' => $product->id,
            'alert_type' => StockAlert::TYPE_WARNING,
            'current_stock' => 8,
            'stock_minimo' => 5,
            'message' => 'Advertencia stock',
            'is_resolved' => false,
        ]);

        $response = $this->patchJson('/api/alerts/' . $alert->id . '/resolve');

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
}
