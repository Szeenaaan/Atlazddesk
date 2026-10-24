<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AmcMailProvider extends Model
{
    protected $fillable = [
        'name', 'driver', 'from_email', 'from_name', 'credentials', 'is_active', 'created_by',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    public function publicData(): array
    {
        return $this->only(['id', 'name', 'driver', 'from_email', 'from_name', 'is_active', 'created_at']) + [
            'credential_configured' => ! empty($this->credentials),
            'smtp' => $this->driver === 'smtp' ? array_intersect_key($this->credentials ?? [],
                array_flip(['host', 'port', 'encryption', 'username'])) : null,
        ];
    }
}
