<?php

namespace Tests\Unit;

use App\Support\PdfLabels;
use PHPUnit\Framework\TestCase;

class PdfLabelsTest extends TestCase
{
    public function test_states_are_readable_and_carry_the_web_tones(): void
    {
        $this->assertSame('En revisión', PdfLabels::label('en_revision'));
        $this->assertSame('Dado de baja', PdfLabels::label('dado_de_baja'));
        $this->assertSame('Certificado de calibración', PdfLabels::label('certificado_calibracion'));
        $this->assertSame('Estado nuevo', PdfLabels::label('estado_nuevo'), 'lo desconocido: mayúscula inicial, sin guiones bajos');
        $this->assertSame('—', PdfLabels::label(null));

        $this->assertSame('ok', PdfLabels::tone('disponible'));
        $this->assertSame('info', PdfLabels::tone('prestado'));
        $this->assertSame('warn', PdfLabels::tone('en_reparacion'));
        $this->assertSame('danger', PdfLabels::tone('rechazado'));
        $this->assertSame('neutral', PdfLabels::tone('descartado'));
    }
}
