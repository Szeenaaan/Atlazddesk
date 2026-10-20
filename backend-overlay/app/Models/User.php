<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['customer_id','name','email','password','role','phone','status','last_login_at'];
    protected $hidden = [
        'password','remember_token','mfa_totp_secret',
        'mfa_totp_pending_secret','mfa_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'mfa_totp_secret' => 'encrypted',
            'mfa_totp_pending_secret' => 'encrypted',
            'mfa_totp_confirmed_at' => 'datetime',
            'mfa_email_enabled' => 'boolean',
            'mfa_recovery_codes' => 'array',
            'mfa_security_version' => 'integer',
        ];
    }

    public function assignedAssets(): \Illuminate\Database\Eloquent\Relations\BelongsToMany { return $this->belongsToMany(\App\Models\Asset::class, 'asset_user')->withTimestamps(); }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function isCustomer(): bool { return in_array($this->role, ['customer_admin','customer_user'], true); }
    public function isTechnician(): bool { return $this->role === 'technician'; }
    public function isStaff(): bool { return in_array($this->role, ['admin'], true); }
}
