<?php

namespace Tests\Unit;

use App\Services\LoanStateMachine;
use App\Services\PurchaseStateMachine;
use App\Services\QuotationStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StateMachineTest extends TestCase
{
    /**
     * Mapa explícito de transiciones permitidas por máquina: cualquier otra
     * combinación debe ser rechazada.
     *
     * @return array<string, array{0: class-string, 1: array<string, array<int, string>>}>
     */
    public static function machines(): array
    {
        return [
            'préstamos' => [LoanStateMachine::class, [
                'pendiente' => ['en_proceso', 'rechazado'],
                'en_proceso' => ['procesado'],
                'procesado' => [],
                'rechazado' => [],
            ]],
            'compras' => [PurchaseStateMachine::class, [
                'pendiente' => ['en_camino'],
                'en_camino' => ['completa'],
                'completa' => [],
            ]],
            'cotizaciones' => [QuotationStateMachine::class, [
                'pendiente' => ['aceptada', 'rechazada'],
                'aceptada' => ['convertida', 'rechazada'],
                'rechazada' => [],
                'convertida' => [],
            ]],
        ];
    }

    #[DataProvider('machines')]
    public function test_only_the_documented_transitions_are_allowed(string $machine, array $expected): void
    {
        $this->assertEqualsCanonicalizing(array_keys($expected), $machine::statuses());

        foreach ($expected as $from => $allowed) {
            $this->assertSame($allowed, $machine::allowedFrom($from), "allowedFrom({$from})");

            foreach ($machine::statuses() as $to) {
                $this->assertSame(
                    in_array($to, $allowed, true),
                    $machine::canTransition($from, $to),
                    "{$from} -> {$to}"
                );
            }
        }
    }

    #[DataProvider('machines')]
    public function test_unknown_statuses_are_invalid_and_have_no_transitions(string $machine, array $expected): void
    {
        $this->assertFalse($machine::isValidStatus('inventado'));
        $this->assertSame([], $machine::allowedFrom('inventado'));
        $this->assertFalse($machine::canTransition('inventado', 'pendiente'));
        $this->assertFalse($machine::canTransition('pendiente', 'inventado'));
    }

    #[DataProvider('machines')]
    public function test_every_machine_has_a_terminal_state(string $machine, array $expected): void
    {
        $terminal = array_filter($expected, fn (array $allowed) => $allowed === []);

        $this->assertNotEmpty($terminal);
        $this->assertTrue($machine::isValidStatus('pendiente'));
    }

    public function test_loan_labels_cover_every_status(): void
    {
        foreach (LoanStateMachine::statuses() as $status) {
            $this->assertNotSame('', LoanStateMachine::labelFor($status));
        }
    }
}
