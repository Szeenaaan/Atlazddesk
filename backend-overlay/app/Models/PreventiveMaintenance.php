<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PreventiveMaintenance extends Model { public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Customer::class); } protected $table='preventive_maintenance'; protected $fillable=['customer_id','location_id','asset_id','assigned_to','title','description','frequency','next_due_date','status']; protected $casts=['next_due_date'=>'date']; }
