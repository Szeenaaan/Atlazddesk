<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TicketAttachment extends Model { protected $fillable=['ticket_id','comment_id','uploaded_by','file_name','file_path','mime_type','file_size']; }
