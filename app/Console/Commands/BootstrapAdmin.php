<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class BootstrapAdmin extends Command
{
    protected $signature = 'app:bootstrap-admin';
    protected $description = 'Cria ou valida o administrador local inicial';

    public function handle(): int
    {
        $username=strtolower(trim((string)env('ADMIN_USERNAME','admin')));
        $name=trim((string)env('ADMIN_NAME','Administrador'));
        $email=strtolower(trim((string)env('ADMIN_EMAIL','admin@local.test')));
        $password=(string)env('ADMIN_PASSWORD','');

        if($password==='' || strlen($password)<10){
            $this->error('ADMIN_PASSWORD deve possuir pelo menos 10 caracteres.');
            return self::FAILURE;
        }

        $user=User::where('username',$username)->first();
        if(!$user){
            User::create([
                '_id'=>'user_'.$username,
                'username'=>$username,'name'=>$name,'email'=>$email,
                'hashed_password'=>Hash::make($password),'role'=>'admin','active'=>true,
                'created_at'=>now(),'updated_at'=>now(),
                'produto_tools_preferences'=>['ui_theme'=>'light'],
            ]);
            $this->info("Administrador {$username} criado.");
            return self::SUCCESS;
        }

        if($user->role!=='admin' || $user->active===false){
            $user->role='admin';$user->active=true;$user->updated_at=now();$user->save();
        }
        $this->info("Administrador {$username} já existe.");
        return self::SUCCESS;
    }
}
