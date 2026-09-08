<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        $users=User::orderBy('name')->get();
        return view('users.index',compact('users'));
    }

    public function store(Request $request)
    {
        $data=$request->validate([
            'username'=>['required','string','max:80'],
            'name'=>['required','string','max:150'],
            'email'=>['required','email','max:180'],
            'password'=>['required','string','min:10'],
            'role'=>['required',Rule::in(['user','head_comercial','admin'])],
        ]);
        $username=strtolower(trim($data['username']));
        if(User::where('username',$username)->exists())return back()->withErrors(['username'=>'Usuário já existe.'])->withInput();
        User::create([
            '_id'=>'user_'.$username,'username'=>$username,'name'=>trim($data['name']),
            'email'=>strtolower(trim($data['email'])),'hashed_password'=>Hash::make($data['password']),
            'role'=>$data['role'],'active'=>true,'created_at'=>now(),'updated_at'=>now(),
            'produto_tools_preferences'=>['ui_theme'=>'light'],
        ]);
        ActivityLogger::add($request->user()->username,'Criou usuário',['username'=>$username]);
        return back()->with('success','Usuário criado.');
    }

    public function update(Request $request,string $username)
    {
        $user=User::where('username',strtolower($username))->firstOrFail();
        $data=$request->validate([
            'name'=>['required','string','max:150'],'email'=>['required','email','max:180'],
            'role'=>['required',Rule::in(['user','head_comercial','admin'])],
            'active'=>['nullable','boolean'],'password'=>['nullable','string','min:10'],
        ]);
        $active=$request->boolean('active');
        if($user->role==='admin' && (!$active || $data['role']!=='admin') && User::where('role','admin')->where('active',true)->count()<=1){
            return back()->withErrors(['user'=>'Não é permitido remover o último administrador ativo.']);
        }
        $user->name=trim($data['name']);$user->email=strtolower(trim($data['email']));
        $user->role=$data['role'];$user->active=$active;$user->updated_at=now();
        if(!empty($data['password']))$user->hashed_password=Hash::make($data['password']);
        $user->save();
        ActivityLogger::add($request->user()->username,'Atualizou usuário',['username'=>$user->username]);
        return back()->with('success','Usuário atualizado.');
    }

    public function destroy(Request $request,string $username)
    {
        $user=User::where('username',strtolower($username))->firstOrFail();
        if($user->username===$request->user()->username)return back()->withErrors(['user'=>'Não exclua a conta usada na sessão atual.']);
        if($user->role==='admin' && User::where('role','admin')->where('active',true)->count()<=1)return back()->withErrors(['user'=>'Não é permitido excluir o último administrador ativo.']);
        $user->delete();
        ActivityLogger::add($request->user()->username,'Excluiu usuário',['username'=>$username]);
        return back()->with('success','Usuário excluído.');
    }
    public function theme(Request $request)
    {
        $data = $request->validate(['theme' => ['required', Rule::in(['light','dark'])]]);
        $user = $request->user();
        $preferences = (array)($user->produto_tools_preferences ?? []);
        $preferences['ui_theme'] = $data['theme'];
        $user->produto_tools_preferences = $preferences;
        $user->updated_at = now();
        $user->save();
        return response()->json(['ok' => true, 'theme' => $data['theme']]);
    }

}
