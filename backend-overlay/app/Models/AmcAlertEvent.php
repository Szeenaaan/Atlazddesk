<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AmcAlertEvent extends Model
{
    protected $fillable=['event_key','event','subject_type','subject_id'];
}
