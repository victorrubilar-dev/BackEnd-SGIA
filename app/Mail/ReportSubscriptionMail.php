<?php

namespace App\Mail;

use App\Reports\ReportRoutes;
use App\Support\ReportSubscriptionPeriod;
use App\Support\XlsxWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Un reporte suscrito (docs/suscripcion-reportes.md, decisiones 4 a 6): resumen, enlace a `/reportes` y, si
 * corresponde, el `.xlsx` adjunto. Lo envía el trabajo en cola, así que el correo en sí no se encola.
 */
class ReportSubscriptionMail extends Mailable
{
    use Queueable, SerializesModels;

    private const FREQUENCIES = ['weekly' => 'semanal', 'monthly' => 'mensual', 'semester' => 'semestral'];

    /**
     * @param  array<string, string>  $filters
     * @param  list<array{name: string, rows: int, truncated: bool}>  $sheets
     * @param  array{name: string, content: string}|null  $file
     */
    public function __construct(
        public string $title,
        public string $recipient,
        public string $frequency,
        public string $kind,
        public ReportSubscriptionPeriod $period,
        public array $filters,
        public array $sheets,
        public string $delivery,
        public bool $empty,
        public bool $personal,
        public string $url,
        public ?array $file = null,
    ) {}

    public function envelope(): Envelope
    {
        $when = match ($this->kind) {
            ReportRoutes::SNAPSHOT => now()->format('d-m-Y'),
            ReportRoutes::YEAR => substr($this->period->from, 0, 4),
            default => $this->period->label(),
        };

        return new Envelope(subject: "SGIA · {$this->title} · {$when}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.report-subscription',
            with: [
                'periodText' => match ($this->kind) {
                    ReportRoutes::SNAPSHOT => 'Estado al '.now()->format('d-m-Y H:i'),
                    ReportRoutes::YEAR => 'Año '.substr($this->period->from, 0, 4),
                    default => ucfirst($this->period->label()).' ('.$this->period->range().')',
                },
                'frequencyText' => self::FREQUENCIES[$this->frequency] ?? $this->frequency,
            ],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        if ($this->file === null) {
            return [];
        }
        $content = $this->file['content'];

        return [Attachment::fromData(fn () => $content, $this->file['name'])->withMime(XlsxWriter::MIME)];
    }

    /** Escapa el Markdown de textos escritos por usuarios (búsquedas, nombres). */
    public static function plain(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_\[\]()<>#|!~])/u', '\\\\$1', str_replace(["\r", "\n"], ' ', $text));
    }
}
