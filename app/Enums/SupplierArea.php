<?php

namespace App\Enums;

/**
 * Áreas fijas de proveedor (nuevo flujo de compras): la solicitud de compra se envía a
 * todos los proveedores activos de las áreas elegidas.
 */
enum SupplierArea: string
{
    case Informatica = 'informatica';
    case Diseno = 'diseno';
    case Telecomunicaciones = 'telecomunicaciones';
    case Ciberseguridad = 'ciberseguridad';

    public function label(): string
    {
        return match ($this) {
            self::Informatica => 'Informática',
            self::Diseno => 'Diseño',
            self::Telecomunicaciones => 'Telecomunicaciones',
            self::Ciberseguridad => 'Ciberseguridad',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
