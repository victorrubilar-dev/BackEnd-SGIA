<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductConditionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_stock_moves_between_available_repair_and_written_off(): void
    {
        $product = Product::factory()->supply()->create(['quantity' => 10]);
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true]));

        $this->patchJson("/api/products/{$product->id}/condition", ['action' => 'send_to_repair', 'quantity' => 4])
            ->assertOk()->assertJsonPath('product.quantity_in_repair', 4)->assertJsonPath('product.quantity', 6);

        $this->patchJson("/api/products/{$product->id}/condition", ['action' => 'repaired', 'quantity' => 1])->assertOk();
        $this->patchJson("/api/products/{$product->id}/condition", ['action' => 'write_off', 'source' => 'repair', 'quantity' => 3])->assertOk();
        $this->patchJson("/api/products/{$product->id}/condition", ['action' => 'write_off', 'source' => 'stock', 'quantity' => 2])->assertOk();

        $fresh = $product->fresh();
        $this->assertSame([5, 0, 5], [$fresh->quantity, $fresh->quantity_in_repair, $fresh->quantity_written_off]);
    }

    public function test_cannot_move_more_units_than_available_and_requires_source_for_write_off(): void
    {
        $product = Product::factory()->supply()->create(['quantity' => 2]);
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_DIRECTOR, 'is_active' => true]));

        $this->patchJson("/api/products/{$product->id}/condition", ['action' => 'send_to_repair', 'quantity' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->patchJson("/api/products/{$product->id}/condition", ['action' => 'write_off', 'quantity' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('source');
        $this->assertSame(2, $product->fresh()->quantity);
    }

    public function test_teacher_cannot_change_condition(): void
    {
        $product = Product::factory()->supply()->create();
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true]));

        $this->patchJson("/api/products/{$product->id}/condition", ['action' => 'send_to_repair', 'quantity' => 1])->assertForbidden();
    }

    public function test_operational_status_summary(): void
    {
        $this->assertSame('disponible', (new Product(['quantity' => 3, 'is_active' => true]))->operationalStatus());
        $this->assertSame('en_reparacion', (new Product(['quantity' => 0, 'quantity_in_repair' => 2, 'is_active' => true]))->operationalStatus());
        $this->assertSame('dado_de_baja', (new Product(['quantity' => 5, 'is_active' => false]))->operationalStatus());
        $this->assertSame('sin_stock', (new Product(['quantity' => 0, 'is_active' => true]))->operationalStatus());
    }
}
