<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class TechnicalMailSetting extends Model
{
    protected $table = 'amc_technical_mail_settings';

    public $incrementing = false;

    protected $fillable = ['id', 'enabled', 'driver', 'from_email', 'from_name', 'credentials', 'updated_by'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'credentials' => 'encrypted:array'];
    }
}
