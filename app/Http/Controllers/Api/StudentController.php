<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Requests\UpdateStudentStatusRequest;
use App\Http\Resources\LoanResource;
use App\Http\Resources\StudentResource;
use App\Models\Loan;
use App\Models\Student;
use App\Rules\ChileanRut;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Registro de estudiantes (REQ-20).
 */
class StudentController extends Controller
{
    /**
     * GET /api/students — filtros `search` (RUT o nombre), `career_id` e `is_active`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Student::class);

        $query = Student::with('career')->withCount('loans');

        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            // El RUT se guarda sin puntos: se busca también la forma limpia.
            $rut = str_replace('.', '', $search);
            $query->where(fn ($q) => $q
                ->where('full_name', 'ilike', "%{$search}%")
                ->orWhere('rut', 'ilike', "%{$rut}%"));
        }

        if ($request->filled('career_id')) {
            $query->where('career_id', $request->integer('career_id'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return StudentResource::collection(
            $query->orderBy('full_name')->orderBy('id')->paginate($this->perPage($request))
        );
    }

    /**
     * GET /api/students/by-rut/{rut} — búsqueda exacta (con o sin puntos y guion).
     */
    public function byRut(string $rut): StudentResource
    {
        Gate::authorize('viewAny', Student::class);

        $student = Student::with('career')->withCount('loans')
            ->where('rut', ChileanRut::normalize($rut))
            ->firstOrFail();

        return new StudentResource($student);
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $student = Student::create($request->validated());

        return (new StudentResource($student->load('career')->loadCount('loans')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Student $student): StudentResource
    {
        Gate::authorize('view', $student);

        return new StudentResource($student->load('career')->loadCount('loans'));
    }

    public function update(UpdateStudentRequest $request, Student $student): StudentResource
    {
        $student->update($request->validated());

        return new StudentResource($student->load('career')->loadCount('loans'));
    }

    /**
     * PATCH /api/students/{student}/status — activa o desactiva. No se borran: su historial de préstamos queda para auditoría.
     */
    public function updateStatus(UpdateStudentStatusRequest $request, Student $student): StudentResource
    {
        $student->forceFill(['is_active' => $request->boolean('is_active')])->save();

        return new StudentResource($student->load('career')->loadCount('loans'));
    }

    /**
     * GET /api/students/{student}/loans — historial, más reciente primero.
     */
    public function loans(Request $request, Student $student): AnonymousResourceCollection
    {
        Gate::authorize('view', $student);

        $loans = Loan::visibleTo($request->user())
            ->where('student_id', $student->id)
            ->with(['items.product.location', 'items.units.cajon.location', 'requester'])
            ->orderByDesc('loan_date')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return LoanResource::collection($loans);
    }
}
