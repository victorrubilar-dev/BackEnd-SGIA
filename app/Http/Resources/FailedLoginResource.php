<?php

namespace App\Http\Resources;

use App\Models\FailedLogin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FailedLogin */
class FailedLoginResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'user_id' => $this->user_id,
            'reason' => $this->reason,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'attempted_at' => $this->attempted_at->toIso8601String(),
        ];
    }
}
