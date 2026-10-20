<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
class AmcService extends Model {
 protected $fillable=['name','description','is_active']; protected $casts=['is_active'=>'boolean'];
 public function contracts():BelongsToMany{return $this->belongsToMany(AmcContract::class, 'amc_contract_service')->withPivot('notes')->withTimestamps();}
}
