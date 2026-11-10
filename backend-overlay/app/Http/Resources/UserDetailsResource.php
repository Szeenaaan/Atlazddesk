<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class UserDetailsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->resource->only(['id', 'name', 'email', 'phone', 'role', 'status', 'created_at', 'last_login_at', 'email_verified_at']);
        if ($this->resource->relationLoaded('customer') && $this->resource->customer) {
            $data['customer'] = $this->resource->customer->only(['id', 'company_name']);
        }
        if ($this->resource->relationLoaded('assignedAssets')) {
            $data['assets'] = $this->resource->assignedAssets->map(fn ($asset) => $asset->only(['id', 'name', 'asset_tag']))->values()->all();
        }
        if ($this->resource instanceof \App\Models\User && $this->resource->role === 'technician') {
            $data['workforce_type'] = $this->resource->workforce_type;
            $data['designation_id'] = $this->resource->designation_id;
            if ($this->resource->relationLoaded('designation')) $data['designation'] = $this->resource->designation?->only(['id', 'name', 'level', 'is_active']);
        }
        return array_filter($data, fn ($value) => $value !== null && $value !== '');
    }
}
