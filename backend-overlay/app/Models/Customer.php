<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = ['company_name','customer_code','trade_license_no','vat_no','phone','email','website','address','city','country','status'];

    public function users(): HasMany { return $this->hasMany(User::class); }
    public function locations(): HasMany { return $this->hasMany(Location::class); }
    public function contracts(): HasMany { return $this->hasMany(AmcContract::class); }
    public function assets(): HasMany { return $this->hasMany(Asset::class); }
    public function tickets(): HasMany { return $this->hasMany(Ticket::class); }
}
