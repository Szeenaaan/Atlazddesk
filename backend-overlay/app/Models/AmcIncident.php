<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AmcIncident extends Model {
    protected $guarded = ['id'];
    protected $casts = ['first_seen_at'=>'datetime','last_seen_at'=>'datetime','resolved_at'=>'datetime'];
}
