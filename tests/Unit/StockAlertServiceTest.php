<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\StockAlert;
use App\Models\User;
use App\Notifications\StockThresholdNotification;
use App\Services\StockAlertService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StockAlertServiceTest extends TestCase
{
    use DatabaseTransactions;

    private StockAlertService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(StockAlertService::class);
        Notification::fake();
    }

    /**
     * @return array<string, array{0: int, 1: string|null}>
     */
    public static function thresholds(): array
    {
        // stock_minimo = 10: aviso hasta 15, crítico hasta 10.
        return [
            'holgado' => [16, null],
            'justo sobre el aviso' => [15, StockAlert::TYPE_WARNING],
            'dentro del aviso' => [12, StockAlert::TYPE_WARNING],
            'justo sobre el mínimo' => [11, StockAlert::TYPE_WARNING],
            'en el mínimo' => [10, StockAlert::TYPE_CRITICAL],
            'bajo el mínimo' => [3, StockAlert::TYPE_CRITICAL],
            'agotado' => [0, StockAlert::TYPE_CRITICAL],
        ];
    }

    #[DataProvider('thresholds')]
    public function test_alert_type_depends_on_the_threshold_reached(int $quantity, ?string $expected): void
    {
        $product = Product::factory()->create(['quantity' => $quantity, 'stock_minimo' => 10]);

        $alert = $this->service->checkStock($product);

        $this->assertSame($expected, $alert?->alert_type);
    }

    public function test_repeated_checks_update_the_same_open_alert_instead_of_duplicating(): void
    {
        $product = Product::factory()->create(['quantity' => 8, 'stock_minimo' => 10]);

        $this->service->checkStock($product);
        $product->update(['quantity' => 6]);
        $this->service->checkStock($product);

        $alerts = StockAlert::where('product_id', $product->id)->where('is_resolved', false)->get();

        $this->assertCount(1, $alerts);
        $this->assertSame(6, $alerts->first()->current_stock);
    }

    public function test_recovering_stock_resolves_open_alerts(): void
    {
        $product = Product::factory()->create(['quantity' => 2, 'stock_minimo' => 10]);
        $this->service->checkStock($product);

        $product->update(['quantity' => 50]);

        $this->assertNull($this->service->checkStock($product));
        $this->assertSame(0, StockAlert::where('product_id', $product->id)->where('is_resolved', false)->count());
    }

    public function test_only_active_admin_director_and_warehouse_users_are_notified(): void
    {
        $notified = collect([User::ROLE_ADMIN, User::ROLE_DIRECTOR, User::ROLE_WAREHOUSE])
            ->map(fn ($role) => User::factory()->create(['role' => $role, 'is_active' => true]));
        $teacher = User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true]);
        $inactive = User::factory()->create(['role' => User::ROLE_ADMIN, 'is_active' => false]);

        $this->service->checkStock(Product::factory()->create(['quantity' => 1, 'stock_minimo' => 10]));

        Notification::assertSentTo($notified->all(), StockThresholdNotification::class);
        Notification::assertNotSentTo([$teacher, $inactive], StockThresholdNotification::class);
    }

    public function test_no_notification_when_stock_is_healthy(): void
    {
        $this->service->checkStock(Product::factory()->create(['quantity' => 100, 'stock_minimo' => 10]));

        Notification::assertNothingSent();
    }
}
