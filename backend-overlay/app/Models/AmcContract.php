<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo,BelongsToMany};
class AmcContract extends Model {
 protected $fillable=['customer_id','sla_policy_id','contract_number','start_date','end_date','contract_type','contract_value','currency','status','notes'];
 protected $casts=['start_date'=>'date','end_date'=>'date','contract_value'=>'decimal:2'];
 public function customer():BelongsTo{return $this->belongsTo(Customer::class);}
 public function slaPolicy():BelongsTo{return $this->belongsTo(SlaPolicy::class);}
 public function services():BelongsToMany{return $this->belongsToMany(AmcService::class, 'amc_contract_service')->withPivot('notes')->withTimestamps();}
}
