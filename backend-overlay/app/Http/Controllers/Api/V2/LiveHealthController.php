<?php
namespace App\Http\Controllers\Api\V2;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\{DB,Cache};
class LiveHealthController extends Controller {
 public function live(){return response()->json(['status'=>'ok']);}
 public function ready(){
  $checks=['database'=>false,'cache'=>false];
  try{DB::select('SELECT 1');$checks['database']=true;}catch(\Throwable $e){}
  $key='amc:health:'.getmypid();
  try{Cache::put($key,'1',10);$checks['cache']=Cache::get($key)==='1';}catch(\Throwable $e){}
  finally{try{Cache::forget($key);}catch(\Throwable $e){$checks['cache']=false;}}
  $ok=!in_array(false,$checks,true);
  return response()->json(['status'=>$ok?'ready':'degraded','checks'=>$checks],$ok?200:503);
 }
}
