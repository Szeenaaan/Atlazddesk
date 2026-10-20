<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TicketStatus extends Model { protected $fillable=['name','slug','sort_order','is_closed','is_active']; protected $casts=['is_closed'=>'boolean','is_active'=>'boolean']; }
