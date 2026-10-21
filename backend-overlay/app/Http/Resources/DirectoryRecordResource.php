<?php

namespace App\Http\Resources;

use App\Models\{AmcContract, Asset, Customer, User};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Explicit public detail DTO: unknown attributes and secrets never enter the response. */
final class DirectoryRecordResource extends JsonResource
{
    private function populated(array $values): array
    {
        return array_filter($values, static fn ($value) => $value !== null
            && (! is_string($value) || trim($value) !== ''));
    }

    private function related(string $name, array $fields): ?array
    {
        if (! $this->resource->relationLoaded($name)) return null;
        $model = $this->resource->getRelation($name);
        return $model instanceof Model ? $this->populated($model->only($fields)) : null;
    }

    public function toArray(Request $request): array
    {
        $fields = match (true) {
            $this->resource instanceof User && $this->resource->role === 'technician' => ['id', 'name', 'email', 'phone', 'role', 'status', 'last_login_at'],
            $this->resource instanceof Customer => ['id', 'company_name', 'customer_code', 'trade_license_no', 'vat_no', 'phone', 'email', 'website', 'address', 'city', 'country', 'status'],
            $this->resource instanceof Asset => ['id', 'name', 'asset_tag', 'manufacturer', 'model', 'serial_number', 'ip_address', 'mac_address', 'purchase_date', 'warranty_expiry', 'license_expiry', 'status', 'notes'],
            $this->resource instanceof AmcContract => ['id', 'contract_number', 'start_date', 'end_date', 'contract_type', 'contract_value', 'currency', 'status', 'notes'],
            default => [],
        };
        $data = $this->resource->only($fields);
        if ($this->resource instanceof Asset || $this->resource instanceof AmcContract) {
            $data['customer'] = $this->related('customer', ['id', 'company_name']);
        }
        if ($this->resource instanceof Asset) {
            $data['category'] = $this->related('category', ['id', 'name']);
            $data['location'] = $this->related('location', ['id', 'name']);
        }
        if ($this->resource instanceof AmcContract) {
            $data['sla_policy'] = $this->related('slaPolicy', ['id', 'name']);
            if ($this->resource->relationLoaded('services')) {
                $data['services'] = $this->resource->getRelation('services')->map(function ($service) {
                    return $this->populated([
                        ...$service->only(['id', 'name', 'description', 'is_active']),
                        'notes' => $service->pivot?->notes,
                    ]);
                })->values()->all();
            }
        }
        return $this->populated($data);
    }
}
