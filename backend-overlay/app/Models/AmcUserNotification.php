<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AmcUserNotification extends Model
{
    protected $fillable = ['user_id','ticket_id','event','title','message','deduplication_key','read_at'];
    protected $hidden = ['deduplication_key'];
    protected function casts(): array { return ['read_at'=>'datetime']; }
}
