<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TicketAssignment extends Model { protected $fillable=['ticket_id','technician_id','assigned_by','assigned_at','unassigned_at']; protected $casts=['assigned_at'=>'datetime','unassigned_at'=>'datetime']; }
