<?php

namespace Tests\Feature;

use App\Models\Cajon;
use App\Models\Career;
use App\Models\EquipmentUnit;
use App\Models\Loan;
use App\Models\Location;
use App\Models\Product;
use App\Models\Storeroom;
use App\Models\User;
use App\Services\EquipmentUnitService;
use App\Services\LoanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Las unidades quedan `prestado` desde la aprobación y se liberan al terminar el horario.
 */
class ReleaseExpiredLoansTest extends TestCase
{
    use DatabaseTransactions;

    private Storeroom $storeroom;

    private Location $lab;

    private Product $product;

    private EquipmentUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storeroom = Storeroom::factory()->create();
        $this->lab = Location::factory()->create(['storeroom_id' => $this->storeroom->id, 'tipo' => Location::TIPO_SALA]);
        $cajon = Cajon::factory()->create(['location_id' => $this->lab->id]);
        $this->product = Product::factory()->create(['quantity' => 0, 'stock_minimo' => 0, 'is_active' => true]);
        $this->unit = EquipmentUnit::factory()->create(['product_id' => $this->product->id, 'cajon_id' => $cajon->id]);
        app(EquipmentUnitService::class)->syncProductCounters($this->product);
    }

    private function approvedLoan(string $date, string $start, string $end): Loan
    {
        $teacher = User::factory()->create(['role' => User::ROLE_TEACHER, 'is_active' => true]);
        $pañolero = User::factory()->create(['role' => User::ROLE_WAREHOUSE, 'is_active' => true, 'storeroom_id' => $this->storeroom->id]);
        $service = app(LoanService::class);

        $loan = $service->createRequest([
            'career_id' => Career::factory()->create()->id,
            'subject' => 'Redes',
            'loan_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'storeroom_id' => $this->storeroom->id,
            'location_id' => $this->lab->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ], $teacher);

        return $service->approve($loan, $pañolero);
    }

    public function test_unit_is_on_loan_from_approval_and_released_after_end_time(): void
    {
        $loan = $this->approvedLoan(today()->toDateString(), '08:00', '10:00');
        $this->assertSame(EquipmentUnit::STATUS_ON_LOAN, $this->unit->fresh()->status);

        $service = app(LoanService::class);
        $today = today('America/Santiago');

        // Antes del término: sigue prestada.
        $this->assertSame(0, $service->releaseExpiredUnits($today->copy()->setTime(9, 59)));
        $this->assertSame(EquipmentUnit::STATUS_ON_LOAN, $this->unit->fresh()->status);

        // Al término: vuelve a estar disponible y el préstamo queda devuelto.
        $this->assertSame(1, $service->releaseExpiredUnits($today->copy()->setTime(10, 0)));
        $this->assertSame(EquipmentUnit::STATUS_AVAILABLE, $this->unit->fresh()->status);
        $this->assertNotNull($loan->fresh()->returned_at);
        $this->assertSame(1, $this->product->fresh()->quantity);

        // Idempotente.
        $this->assertSame(0, $service->releaseExpiredUnits($today->copy()->setTime(11, 0)));
    }

    public function test_loan_from_a_previous_day_is_released(): void
    {
        $this->approvedLoan(today()->toDateString(), '08:00', '10:00');

        $released = app(LoanService::class)->releaseExpiredUnits(today('America/Santiago')->addDay()->setTime(0, 1));

        $this->assertSame(1, $released);
        $this->assertSame(EquipmentUnit::STATUS_AVAILABLE, $this->unit->fresh()->status);
    }

    public function test_future_loan_is_not_released(): void
    {
        $this->approvedLoan(today()->addDays(3)->toDateString(), '08:00', '10:00');

        $this->assertSame(0, app(LoanService::class)->releaseExpiredUnits());
        $this->assertSame(EquipmentUnit::STATUS_ON_LOAN, $this->unit->fresh()->status);
    }

    public function test_command_runs(): void
    {
        $this->artisan('sgia:release-expired-loans')->assertSuccessful();
    }
}
