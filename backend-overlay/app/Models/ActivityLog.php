<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ActivityLog extends Model { protected $fillable=['user_id','customer_id','event','subject_type','subject_id','request_id','ip_address','user_agent','properties']; protected $casts=['properties'=>'array']; }
