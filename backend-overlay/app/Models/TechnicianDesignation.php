<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Installation-scoped, like TicketCategory: one contractor per database. */
class TechnicianDesignation extends Model
{
    protected $fillable = ['name', 'level', 'is_active'];
    protected $casts = ['level' => 'integer', 'is_active' => 'boolean'];
}
