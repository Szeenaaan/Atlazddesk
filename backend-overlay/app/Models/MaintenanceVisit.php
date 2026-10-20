<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MaintenanceVisit extends Model { protected $fillable=['preventive_maintenance_id','technician_id','ticket_id','scheduled_at','started_at','completed_at','status','notes']; protected $casts=['scheduled_at'=>'datetime','started_at'=>'datetime','completed_at'=>'datetime']; }
