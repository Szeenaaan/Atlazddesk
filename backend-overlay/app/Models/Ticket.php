<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo,HasMany};
class Ticket extends Model {
 protected $fillable=['ticket_number','customer_id','location_id','asset_id','created_by','assigned_to','category_id','priority_id','status_id','sla_policy_id','subject','description','sla_response_due_at','sla_resolution_due_at','first_response_at','resolved_at','closed_at'];
 protected $casts=['sla_response_due_at'=>'datetime','sla_resolution_due_at'=>'datetime','first_response_at'=>'datetime','resolved_at'=>'datetime','closed_at'=>'datetime'];
 public function customer():BelongsTo{return $this->belongsTo(Customer::class);}
 public function location():BelongsTo{return $this->belongsTo(Location::class);}
 public function asset():BelongsTo{return $this->belongsTo(Asset::class);}
 public function creator():BelongsTo{return $this->belongsTo(User::class,'created_by');}
 public function assignee():BelongsTo{return $this->belongsTo(User::class,'assigned_to');}
 public function category():BelongsTo{return $this->belongsTo(TicketCategory::class,'category_id');}
 public function priority():BelongsTo{return $this->belongsTo(TicketPriority::class,'priority_id');}
 public function status():BelongsTo{return $this->belongsTo(TicketStatus::class,'status_id');}
 public function slaPolicy():BelongsTo{return $this->belongsTo(SlaPolicy::class);}
 public function comments():HasMany{return $this->hasMany(TicketComment::class);}
 public function attachments():HasMany{return $this->hasMany(TicketAttachment::class);}
 public function workLogs():HasMany{return $this->hasMany(TicketWorkLog::class);}
 public function assignments():HasMany{return $this->hasMany(TicketAssignment::class);}
}
