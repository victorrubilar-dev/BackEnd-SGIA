<?php

namespace Tests\Concerns;

use Symfony\Component\Process\Process;

/**
 * Texto de un PDF generado con las fuentes de la web (docs/pdf-estilo-trazo.md). Con Geist embebido DomPDF
 * escribe el texto codificado (Identity-H), así que se extrae con `pdftotext` (poppler-utils, el mismo que usa
 * el OCR local). Usa Symfony Process directo para no chocar con `Process::fake()` de otros tests.
 */
trait ExtractsPdfText
{
    protected function pdfText(string $pdf): string
    {
        $process = new Process(['pdftotext', '-enc', 'UTF-8', '-', '-']);
        $process->setInput($pdf);
        $process->run();

        $this->assertTrue($process->isSuccessful(), 'pdftotext (poppler-utils) es necesario para leer los PDFs: '.$process->getErrorOutput());

        // Una sola línea con espacios simples: las celdas angostas parten el texto en varias líneas.
        return (string) preg_replace('/\s+/u', ' ', $process->getOutput());
    }
}
