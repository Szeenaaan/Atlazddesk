<?php
namespace App\Http\Controllers\Api\V2;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\{DB,Cache};
class LiveHealthController extends Controller {
 public function live(){return response()->json(['status'=>'ok']);}
 public function ready(){
  $checks=['database'=>false,'cache'=>false];
  try{DB::select('SELECT 1');$checks['database']=true;}catch(\Throwable $e){}
  try{$key='amc:health:'.getmypid();Cache::put($key,1,10);$checks['cache']=Cache::get($key)===1;Cache::forget($key);}catch(\Throwable $e){}
  $ok=!in_array(false,$checks,true);
  return response()->json(['status'=>$ok?'ready':'degraded','checks'=>$checks],$ok?200:503);
 }
}
