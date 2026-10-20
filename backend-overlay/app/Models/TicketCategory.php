<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TicketCategory extends Model { protected $fillable=['name','slug','is_active']; protected $casts=['is_active'=>'boolean']; public function assets(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(Asset::class, 'category_id'); } }
