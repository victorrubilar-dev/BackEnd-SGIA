<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStudentRequest;
use App\Http\Requests\UpdateStudentRequest;
use App\Http\Resources\LoanResource;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use App\Rules\ChileanRut;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Registro de estudiantes (REQ-20).
 */
class StudentController extends Controller
{
    /**
     * GET /api/students — filtros `search` (RUT o nombre) y `career_id`.
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
     * DELETE /api/students/{student} — `422` si tiene préstamos.
     */
    public function destroy(Student $student): Response|JsonResponse
    {
        Gate::authorize('delete', $student);

        if ($student->loans()->exists()) {
            return $this->unprocessable('El estudiante tiene préstamos registrados y no se puede eliminar.', 'student');
        }

        $student->delete();

        return response()->noContent();
    }

    /**
     * GET /api/students/{student}/loans — historial, más reciente primero.
     */
    public function loans(Request $request, Student $student): AnonymousResourceCollection
    {
        Gate::authorize('view', $student);

        $loans = $student->loans()
            ->with(['items.product.location', 'items.units', 'requester'])
            ->orderByDesc('loan_date')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return LoanResource::collection($loans);
    }
}
