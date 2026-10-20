<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Asset extends Model {
 protected $fillable=['customer_id','category_id','location_id','asset_type_id','asset_tag','name','manufacturer','model','serial_number','ip_address','mac_address','purchase_date','warranty_expiry','license_expiry','status','notes'];
 protected $casts=['purchase_date'=>'date','warranty_expiry'=>'date','license_expiry'=>'date'];
 public function category():BelongsTo{return $this->belongsTo(TicketCategory::class);}
 public function customer():BelongsTo{return $this->belongsTo(Customer::class);}
 public function location():BelongsTo{return $this->belongsTo(Location::class);}
 public function type():BelongsTo{return $this->belongsTo(AssetType::class,'asset_type_id');}
}
