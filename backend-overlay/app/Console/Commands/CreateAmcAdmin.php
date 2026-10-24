<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAmcAdmin extends Command
{
    protected $signature = 'amc:create-admin {email?}';
    protected $description = 'Provision a super administrator using an interactive secret prompt';

    public function handle(): int
    {
        // One initial technical owner per installation; no shared vendor backdoor.
        if (User::query()->where('role', 'super_admin')->exists()) {
            $this->error('A Super Admin already exists. Use a documented recovery procedure; do not create another bootstrap account.');
            return self::FAILURE;
        }
        $email = (string) ($this->argument('email') ?: $this->ask('Administrator email'));
        $name = (string) $this->ask('Administrator name', 'SupportDesk Administrator');
        $password = (string) $this->secret('Password (minimum 8 characters, mixed case, numbers, symbols)');
        $confirmation = (string) $this->secret('Confirm password');
        $validator = Validator::make(compact('email','password','confirmation'), [
            'email'=>['required','email','max:255','unique:users,email'],
            'password'=>['required','same:confirmation',Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());
            return self::FAILURE;
        }
        User::create(['name'=>$name,'email'=>$email,'password'=>Hash::make($password),
            'role'=>'super_admin','status'=>'active','customer_id'=>null]);
        $this->info('Super administrator created. Enable MFA before public launch.');
        return self::SUCCESS;
    }
}
