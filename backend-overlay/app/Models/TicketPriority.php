<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TicketPriority extends Model { protected $fillable=['name','slug','level','is_active']; protected $casts=['is_active'=>'boolean']; }
