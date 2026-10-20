<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TicketWorkLog extends Model { protected $fillable=['ticket_id','user_id','start_time','end_time','duration_minutes','description']; protected $casts=['start_time'=>'datetime','end_time'=>'datetime']; }
