<?php

namespace Database\Seeders;

use App\Enums\SupplierArea;
use App\Models\Supplier;
use App\Rules\ChileanRut;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Proveedores de demostración, todos con área y al menos 3 activos por área, para poder
 * enviar una solicitud de compra a cualquier área y recibir las 3 cotizaciones mínimas.
 * Lo usan `RealisticDemoSeeder` e `InvoiceDemoSeeder`; es idempotente (por RUT).
 */
class DemoSupplierSeeder extends Seeder
{
    /** clave => [razón social, RUT, dirección, correo, teléfono, rubro, contacto, áreas] */
    public const SUPPLIERS = [
        'electroplus' => ['Soc. Electroplus Ltda.', '77.929.326-2', 'General Mackenna #494, Temuco', 'electro2024temuco@gmail.com', '+56 9 6109 6655', 'Artículos eléctricos', 'Electroplus', ['telecomunicaciones']],
        'arbol' => ['Árbolvisual SpA', '77.883.292-5', 'Nueva York 53 of 61, Santiago', 'mgonzalez@arbolvisual.cl', '+56 2 2638 4410', 'Material publicitario y arte oficina', 'M. González', ['diseno']],
        'systelec' => ['Systelec Electrónica Limitada', '78.481.580-3', 'Conchalí, Santiago', 'systelec@systelec.cl', '+56 2 2736 2200', 'Equipamiento e insumos electrónicos', 'Ventas Systelec', ['telecomunicaciones', 'informatica']],
        'zenner' => ['Sociedad de Inversiones en Tecnología ZE', '76.088.347-6', 'Porvenir 474, Santiago', 'carlos.iglesias@zenner.cl', '+56 2 2840 1500', 'Automatización', 'Carlos Iglesias', ['informatica']],
        'microtec' => ['Microtec SpA', '96.532.730-4', 'Salar Ascotán, Parque Enea 1281, Santiago', 'eduardopalma@micro.cl', '+56 2 2595 4600', 'Instrumentación industrial', 'Cristián Flores', ['telecomunicaciones']],
        'redsur' => ['Redes del Sur SpA', '76.412.905-1', 'Manuel Montt 1120, Temuco', 'ventas@redesdelsur.cl', '+56 45 2218 770', 'Redes y telecomunicaciones', 'Marcela Aravena', ['telecomunicaciones', 'ciberseguridad']],
        'compintegral' => ['Computación Integral Ltda.', '79.554.210-6', 'Bulnes 540, Temuco', 'cotizaciones@compintegral.cl', '+56 45 2234 900', 'Computación y notebooks', 'Rodrigo Henríquez', ['informatica', 'diseno']],
        'ciberlab' => ['CiberLab Chile SpA', '77.120.668-K', 'Av. Providencia 1760, Santiago', 'contacto@ciberlab.cl', '+56 2 2951 3300', 'Ciberseguridad y forense', 'Natalia Pizarro', ['ciberseguridad', 'informatica']],
        'graficasur' => ['Gráfica del Sur SpA', '76.543.219-7', 'Antonio Varas 876, Temuco', 'ventas@graficadelsur.cl', '+56 45 2276 410', 'Impresión, plotters e insumos de diseño', 'Paula Catrileo', ['diseno']],
        'segurinet' => ['SeguriNet Ltda.', '77.345.120-6', 'Av. Alemania 0671, Temuco', 'cotizaciones@segurinet.cl', '+56 45 2290 115', 'Seguridad informática y licencias', 'Felipe Ñanco', ['ciberseguridad', 'informatica']],
    ];

    public function run(): void
    {
        self::suppliers();
    }

    /**
     * Crea o actualiza los proveedores de demostración con sus áreas.
     *
     * @return Collection<string, Supplier>
     */
    public static function suppliers(): Collection
    {
        return collect(self::SUPPLIERS)->map(function (array $d) {
            // El RUT se guarda normalizado (sin puntos): buscar así para que sea idempotente.
            $supplier = Supplier::updateOrCreate(['rut' => ChileanRut::normalize($d[1])], [
                'name' => $d[0], 'address' => $d[2], 'email' => $d[3], 'phone' => $d[4],
                'category' => $d[5], 'contact_name' => $d[6], 'is_active' => true,
            ]);
            $supplier->syncAreas($d[7]);

            return $supplier;
        });
    }

    /**
     * Proveedores activos de demostración por área (para verificar la cobertura mínima).
     *
     * @return array<string, int>
     */
    public static function activeCountByArea(): array
    {
        return collect(SupplierArea::values())
            ->mapWithKeys(fn (string $area) => [$area => collect(self::SUPPLIERS)->filter(fn ($d) => in_array($area, $d[7], true))->count()])
            ->all();
    }
}
