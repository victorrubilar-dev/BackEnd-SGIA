<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre ?? $this->name,
            'name' => $this->nombre ?? $this->name,
            'email' => $this->email,
            'rol_id' => $this->rol_id,
            'rol' => $this->rol?->nombre,
            'role' => $this->role ?? $this->rol?->nombre,
            'panol_id' => $this->panol_id,
            'panol' => $this->panol?->nombre,
            'activo' => (bool) ($this->activo ?? $this->is_active),
            'is_active' => (bool) ($this->activo ?? $this->is_active),
            'carreras' => $this->relationLoaded('carreras') ? $this->carreras->map(fn ($c) => [
                'id' => $c->id,
                'codigo' => $c->codigo,
                'nombre' => $c->nombre,
            ]) : [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
