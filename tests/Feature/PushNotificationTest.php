<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\Product;
use App\Models\StockAlert;
use App\Models\User;
use App\Notifications\Channels\ExpoPushChannel;
use App\Notifications\StockThresholdNotification;
use App\Services\StockAlertService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushNotificationTest extends TestCase
{
    use DatabaseTransactions;

    private const TOKEN = 'ExponentPushToken[abc123]';

    public function test_user_registers_and_removes_a_device_token(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/devices', ['token' => self::TOKEN, 'platform' => 'android'])
            ->assertCreated()->json('id');

        $this->assertDatabaseHas('device_tokens', ['user_id' => $user->id, 'token' => self::TOKEN]);

        $this->deleteJson("/api/devices/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('device_tokens', ['id' => $id]);
    }

    public function test_token_format_is_validated(): void
    {
        Sanctum::actingAs(User::factory()->create(['is_active' => true]));

        $this->postJson('/api/devices', ['token' => 'cualquier-cosa'])->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->postJson('/api/devices', ['token' => self::TOKEN, 'platform' => 'windows'])->assertUnprocessable();
    }

    public function test_registering_the_same_token_moves_it_to_the_new_user_and_others_cannot_delete_it(): void
    {
        $first = User::factory()->create(['is_active' => true]);
        $second = User::factory()->create(['is_active' => true]);

        Sanctum::actingAs($first);
        $id = $this->postJson('/api/devices', ['token' => self::TOKEN])->json('id');

        Sanctum::actingAs($second);
        $this->deleteJson("/api/devices/{$id}")->assertNotFound();
        $this->postJson('/api/devices', ['token' => self::TOKEN])->assertCreated();

        $this->assertSame(1, DeviceToken::where('token', self::TOKEN)->count());
        $this->assertSame($second->id, DeviceToken::where('token', self::TOKEN)->value('user_id'));
    }

    public function test_stock_alert_is_pushed_to_expo_and_invalid_tokens_are_pruned(): void
    {
        $director = User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]);
        DeviceToken::create(['user_id' => $director->id, 'token' => self::TOKEN]);
        DeviceToken::create(['user_id' => $director->id, 'token' => 'ExponentPushToken[muerto]']);

        Http::fake([
            '*' => Http::response(['data' => [
                ['status' => 'ok', 'id' => '1'],
                ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
            ]]),
        ]);

        $product = Product::factory()->create(['quantity' => 3, 'stock_minimo' => 5]);
        app(StockAlertService::class)->checkStock($product);

        Http::assertSent(function ($request) {
            $first = $request->data()[0] ?? [];

            return str_contains($request->url(), 'exp.host')
                && $first['to'] === self::TOKEN
                && $first['title'] === 'Stock crítico';
        });

        $this->assertDatabaseHas('device_tokens', ['token' => self::TOKEN]);
        $this->assertDatabaseMissing('device_tokens', ['token' => 'ExponentPushToken[muerto]']);
    }

    public function test_expo_outage_does_not_break_the_flow(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]);
        DeviceToken::create(['user_id' => $user->id, 'token' => self::TOKEN]);

        Http::fake(['*' => Http::response('boom', 500)]);

        $product = Product::factory()->create(['quantity' => 1, 'stock_minimo' => 5]);
        app(StockAlertService::class)->checkStock($product);

        $this->assertDatabaseHas('stock_alerts', ['product_id' => $product->id]);
    }

    public function test_stock_notification_uses_mail_broadcast_and_expo_channels(): void
    {
        $notification = new StockThresholdNotification(
            StockAlert::create([
                'product_id' => Product::factory()->create()->id,
                'alert_type' => 'critical',
                'current_stock' => 1,
                'stock_minimo' => 5,
                'message' => 'Crítico',
                'is_resolved' => false,
            ])
        );

        $this->assertSame(['broadcast', 'mail', ExpoPushChannel::class], $notification->via(new User));

        Notification::fake();
    }
}
