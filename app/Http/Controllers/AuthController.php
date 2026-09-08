<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function show()
    {
        if(Auth::check())return redirect()->route('dashboard');
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials=$request->validate(['username'=>['required','string'],'password'=>['required','string']]);
        $username=strtolower(trim($credentials['username']));
        $key='login:'.$request->ip().':'.$username;

        if(RateLimiter::tooManyAttempts($key,5)){
            return back()->withErrors(['username'=>'Muitas tentativas. Tente novamente em '.RateLimiter::availableIn($key).' segundos.'])->onlyInput('username');
        }

        $user=User::where('username',$username)->first();
        if(!$user || $user->active===false || !Hash::check($credentials['password'],(string)$user->hashed_password)){
            RateLimiter::hit($key,60);
            return back()->withErrors(['username'=>'Usuário ou senha inválidos.'])->onlyInput('username');
        }

        RateLimiter::clear($key);
        Auth::login($user,true);
        $request->session()->regenerate();
        ActivityLogger::add($username,'Login no Fluxos Luiz');
        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        ActivityLogger::add($request->user()?->username,'Logout do Fluxos Luiz');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
