<?php

namespace App\Services;

use App\Http\Requests\StoreIncidentReportRequest;
use App\Models\EquipmentUnit;
use App\Models\IncidentReport;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Notifications\IncidentReportNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Informe de novedades del docente (REQ-13): guarda el formulario, la firma y la foto en
 * disco privado, asocia el equipo por código y avisa a pañol, administración y dirección.
 */
class IncidentReportService
{
    /**
     * @param  array<string, mixed>  $data  datos validados por StoreIncidentReportRequest
     */
    public function create(array $data, User $teacher, ?UploadedFile $photo): IncidentReport
    {
        [$product, $unit] = $this->resolveEquipment($data['equipment_code'] ?? null);

        $problems = array_values(array_map(
            fn (array $problem) => ['type' => $problem['type'], 'description' => trim($problem['description'])],
            $data['problems']
        ));

        $signaturePath = 'incidents/signatures/'.Str::uuid().'.png';
        Storage::disk('local')->put($signaturePath, (string) StoreIncidentReportRequest::decodeSignature($data['signature']));

        $photoPath = $photo?->store('incidents/evidence', 'local');

        $report = IncidentReport::create([
            'code' => IncidentReport::generateUniqueCode(),
            'product_id' => $product?->id,
            'equipment_unit_id' => $unit?->id,
            'reported_by' => $teacher->id,
            'teacher_name' => $teacher->name,
            'subject' => $data['subject'],
            'report_date' => $data['report_date'],
            'location_id' => $data['location_id'],
            'problems' => $problems,
            'suggestion' => $data['suggestion'] ?? null,
            'title' => 'Informe de novedades — '.$data['subject'],
            'description' => $this->summary($problems, $data['suggestion'] ?? null),
            'severity' => IncidentReport::SEVERITY_MEDIUM,
            'request_type' => IncidentReport::REQUEST_FAILURE,
            'status' => IncidentReport::STATUS_REPORTED,
            'evidence_photo_path' => $photoPath ?: null,
            'signature_path' => $signaturePath,
            'signed_at' => now(),
        ]);

        $this->notifyStaff($report);

        return $report->load(['reporter', 'location', 'product', 'equipmentUnit']);
    }

    /**
     * Avisa al docente que reportó cuando cambia el estado de su informe.
     */
    public function notifyStatusChange(IncidentReport $report, string $from): void
    {
        $reporter = User::find($report->reported_by);

        if ($reporter && $reporter->is_active) {
            $reporter->notify(new IncidentReportNotification(
                $report,
                "Tu informe {$report->code} pasó de \"{$from}\" a \"{$report->status}\"."
            ));
        }
    }

    /**
     * Unidad (por su código) o producto (por su código de barras); null si no se indicó código.
     *
     * @return array{0: Product|null, 1: EquipmentUnit|null}
     *
     * @throws ValidationException
     */
    private function resolveEquipment(?string $code): array
    {
        if (blank($code)) {
            return [null, null];
        }

        $unit = EquipmentUnit::with('product')->where('code', $code)->first();

        if ($unit) {
            return [Product::find($unit->product_id), $unit];
        }

        $product = Product::where('barcode', $code)->first();

        if (! $product) {
            throw ValidationException::withMessages(['equipment_code' => 'No existe un equipo con ese código.']);
        }

        if ($product->hasUnits()) {
            throw ValidationException::withMessages(['equipment_code' => 'Indica el código de la unidad (no el del modelo).']);
        }

        return [$product, null];
    }

    /**
     * @param  array<int, array{type: string, description: string}>  $problems
     */
    private function summary(array $problems, ?string $suggestion): string
    {
        $lines = array_map(fn (array $p) => IncidentReport::PROBLEM_LABELS[$p['type']].': '.$p['description'], $problems);

        if (filled($suggestion)) {
            $lines[] = 'Sugerencia: '.$suggestion;
        }

        return implode("\n", $lines);
    }

    private function notifyStaff(IncidentReport $report): void
    {
        $recipients = User::withRole([User::ROLE_WAREHOUSE, User::ROLE_ADMIN, User::ROLE_DIRECTOR])
            ->where('is_active', true)
            ->get();

        if ($recipients->isNotEmpty()) {
            $lab = Location::find($report->location_id);
            Notification::send($recipients, new IncidentReportNotification(
                $report,
                "{$report->teacher_name} reportó novedades en ".($lab->nombre ?? $lab->sala ?? 'el laboratorio')." ({$report->code})."
            ));
        }
    }
}
