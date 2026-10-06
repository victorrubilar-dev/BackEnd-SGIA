<?php

namespace Tests\Feature;

use App\Models\IncidentReport;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TechnicalSheetTest extends TestCase
{
    use DatabaseTransactions;

    protected User $teacher;

    protected User $director;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create([
            'email' => 'pro_ficha_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_TEACHER,
            'is_active' => true,
        ]);

        $this->director = User::factory()->create([
            'email' => 'dir_ficha_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_DIRECTOR,
            'is_active' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Multimetro Digital Fluke',
            'barcode' => 'SGIA-7777000111',
        ]);
    }

    /**
     * Extrae el contenido de texto de los streams de un PDF (por si vienen
     * comprimidos con deflate), para poder asertar el contenido real.
     */
    private function pdfText(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);

        $text = '';

        foreach ($matches[1] ?? [] as $raw) {
            $decoded = @gzuncompress($raw);
            if ($decoded === false) {
                $decoded = @gzdeflate($raw);
            }
            $text .= $decoded === false ? $raw : $decoded;
        }

        return $text;
    }

    public function test_guest_cannot_download_technical_sheet(): void
    {
        $response = $this->getJson("/api/equipment/{$this->product->id}/technical-sheet");

        $response->assertStatus(401);
    }

    public function test_any_authenticated_user_can_download_technical_sheet_pdf(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->get("/api/equipment/{$this->product->id}/technical-sheet");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('Content-Disposition')
        );
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_technical_sheet_contains_equipment_data_and_history(): void
    {
        IncidentReport::factory()->create([
            'product_id' => $this->product->id,
            'title' => 'Falla en pantalla',
            'description' => 'La pantalla del multimetro no enciende de forma consistente.',
        ]);

        Sanctum::actingAs($this->director);

        $response = $this->get("/api/equipment/{$this->product->id}/technical-sheet");

        $response->assertStatus(200);

        $text = $this->pdfText((string) $response->getContent());

        $this->assertStringContainsString('Ficha T', $text);
        $this->assertStringContainsString('Multimetro Digital Fluke', $text);
        $this->assertStringContainsString('SGIA-7777000111', $text);
        $this->assertStringContainsString('Falla en pantalla', $text);
    }

    public function test_technical_sheet_returns_404_for_unknown_equipment(): void
    {
        Sanctum::actingAs($this->director);

        $this->getJson('/api/equipment/9999999/technical-sheet')->assertStatus(404);
    }

    public function test_reports_of_equipment_are_listed_as_json(): void
    {
        IncidentReport::factory()->create(['product_id' => $this->product->id]);
        IncidentReport::factory()->create(['product_id' => $this->product->id]);
        // Informe de OTRO equipo: no debe aparecer en la hoja de vida.
        IncidentReport::factory()->create();

        Sanctum::actingAs($this->director);

        $response = $this->getJson("/api/equipment/{$this->product->id}/reports");

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonStructure([
            'data' => [['id', 'code', 'description', 'severity', 'severity_label', 'status']],
            'links',
            'meta',
        ]);
    }

    public function test_reports_can_be_filtered_by_severity(): void
    {
        IncidentReport::factory()->create([
            'product_id' => $this->product->id,
            'severity' => IncidentReport::SEVERITY_HIGH,
        ]);
        IncidentReport::factory()->create([
            'product_id' => $this->product->id,
            'severity' => IncidentReport::SEVERITY_LOW,
        ]);

        Sanctum::actingAs($this->director);

        $response = $this->getJson("/api/equipment/{$this->product->id}/reports?severity=critica");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_reports_of_equipment_can_be_downloaded_as_pdf(): void
    {
        IncidentReport::factory()->create([
            'product_id' => $this->product->id,
            'title' => 'Cable danado',
        ]);

        Sanctum::actingAs($this->director);

        $response = $this->get("/api/equipment/{$this->product->id}/reports?format=pdf");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());

        $text = $this->pdfText((string) $response->getContent());
        $this->assertStringContainsString('Cable danado', $text);
        $this->assertStringContainsString('Multimetro Digital Fluke', $text);
    }

    public function test_empty_reports_download_as_pdf_is_still_valid(): void
    {
        Sanctum::actingAs($this->director);

        $response = $this->get("/api/equipment/{$this->product->id}/reports?format=pdf");

        $response->assertStatus(200);
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_teacher_cannot_list_reports_of_equipment(): void
    {
        Sanctum::actingAs($this->teacher);

        $this->getJson("/api/equipment/{$this->product->id}/reports")->assertStatus(403);
    }

    public function test_warehouse_can_list_reports_of_equipment(): void
    {
        $warehouse = User::factory()->create([
            'email' => 'pan_ficha_' . uniqid() . '@inacap.cl',
            'role' => User::ROLE_WAREHOUSE,
            'is_active' => true,
        ]);

        Sanctum::actingAs($warehouse);

        $this->getJson("/api/equipment/{$this->product->id}/reports")->assertStatus(200);
    }
}
