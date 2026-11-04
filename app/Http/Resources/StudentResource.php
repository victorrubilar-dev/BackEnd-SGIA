<?php

namespace App\Http\Resources;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Student
 */
class StudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rut' => $this->rut,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'career_id' => $this->career_id,
            'career' => new CareerResource($this->whenLoaded('career')),
            'loans_count' => $this->whenCounted('loans'),
            'registered_at' => $this->registered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
