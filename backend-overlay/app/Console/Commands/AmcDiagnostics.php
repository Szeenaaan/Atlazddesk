<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB,Cache};
class AmcDiagnostics extends Command {
 protected $signature='amc:diagnostics';
 protected $description='Check AtlasDesk application dependencies without exposing credentials';
 public function handle():int {
  try{DB::select('SELECT 1');$this->info('Database: OK');}catch(\Throwable $e){$this->error('Database: FAIL');return self::FAILURE;}
  try{Cache::put('amc:diagnostics',1,10);$this->info('Cache: '.(Cache::get('amc:diagnostics')===1?'OK':'FAIL'));Cache::forget('amc:diagnostics');}catch(\Throwable $e){$this->error('Cache: FAIL');return self::FAILURE;}
  return self::SUCCESS;
 }
}
