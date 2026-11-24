<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockAlert;
use App\Models\User;
use App\Services\StockAlertService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Quitar una alerta de la campana solo para el usuario (docs/exportaciones-y-campana.md).
 */
class StockAlertDismissTest extends TestCase
{
    use DatabaseTransactions;

    private User $warehouse;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->warehouse = User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]);
        $this->director = User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]);
    }

    private function criticalAlert(): StockAlert
    {
        $product = Product::factory()->supply()->create(['quantity' => 1, 'stock_minimo' => 5, 'is_active' => true]);
        app(StockAlertService::class)->reconcile();

        return StockAlert::where('product_id', $product->id)->where('is_resolved', false)->firstOrFail();
    }

    /** @return array{ids: list<int>, active: int, critical: int} */
    private function bell(): array
    {
        $json = $this->getJson('/api/alerts/critical-stock?exclude_dismissed=1&per_page=100')->assertOk()->json();

        return [
            'ids' => collect($json['data'])->pluck('id')->all(),
            'active' => $json['counts']['active'],
            'critical' => $json['counts']['critical'],
        ];
    }

    public function test_dismiss_hides_the_alert_only_for_that_user_and_lowers_the_bell_totals(): void
    {
        $alert = $this->criticalAlert();

        Sanctum::actingAs($this->warehouse);
        $before = $this->bell();
        $this->assertContains($alert->id, $before['ids']);

        $this->postJson("/api/alerts/{$alert->id}/dismiss")->assertNoContent();
        $this->postJson("/api/alerts/{$alert->id}/dismiss")->assertNoContent(); // idempotente

        $after = $this->bell();
        $this->assertNotContains($alert->id, $after['ids']);
        $this->assertSame($before['active'] - 1, $after['active']);
        $this->assertSame($before['critical'] - 1, $after['critical']);
        $this->assertDatabaseCount('stock_alert_dismissals', 1);

        // Sin `exclude_dismissed` (bandeja /alertas) sigue apareciendo y la alerta no se resolvió.
        $ids = collect($this->getJson('/api/alerts/critical-stock?per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($alert->id, $ids);
        $this->assertFalse($alert->fresh()->is_resolved);

        // Otro usuario la sigue viendo en su campana.
        Sanctum::actingAs($this->director);
        $this->assertContains($alert->id, $this->bell()['ids']);
    }

    public function test_dismiss_is_restricted_by_role_and_requires_an_existing_alert(): void
    {
        $alert = $this->criticalAlert();

        foreach ([User::ROLE_ADMIN, User::ROLE_TEACHER] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
            $this->postJson("/api/alerts/{$alert->id}/dismiss")->assertForbidden();
        }
        $this->assertDatabaseCount('stock_alert_dismissals', 0);

        Sanctum::actingAs($this->director);
        $this->postJson('/api/alerts/999999999/dismiss')->assertNotFound();
    }
}
