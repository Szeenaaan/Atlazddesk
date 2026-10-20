<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Location extends Model {
 protected $fillable=['customer_id','name','address','city','contact_person','contact_phone','status'];
 public function customer():BelongsTo{return $this->belongsTo(Customer::class);}
}
