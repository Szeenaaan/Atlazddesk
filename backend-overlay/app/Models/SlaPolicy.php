<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SlaPolicy extends Model {
 protected $fillable=['name','critical_response_minutes','critical_resolution_minutes','high_response_minutes','high_resolution_minutes','medium_response_minutes','medium_resolution_minutes','low_response_minutes','low_resolution_minutes','is_active'];
 protected $casts=['is_active'=>'boolean'];
}
