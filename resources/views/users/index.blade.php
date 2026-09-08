@extends('layouts.app')
@section('title','Gestão de Acesso')
@section('topbar','Gestão de Acesso')
@section('content')
<div class="page-head"><div><h1>Gestão de Acesso</h1><p>Administre a mesma coleção <code>users</code> utilizada pela aplicação.</p></div></div>
<div class="grid grid-2">
<section class="card"><h3>Novo usuário</h3><form method="post" action="{{ route('users.store') }}" class="stack">@csrf
<div class="form-grid"><div class="field"><label>Usuário</label><input name="username" required></div><div class="field"><label>Nome</label><input name="name" required></div></div>
<div class="field"><label>E-mail</label><input type="email" name="email" required></div><div class="form-grid"><div class="field"><label>Senha</label><input type="password" name="password" minlength="10" required></div><div class="field"><label>Perfil</label><select name="role"><option value="user">Usuário</option><option value="head_comercial">Head Comercial</option><option value="admin">Administrador</option></select></div></div>
<button class="btn btn-primary">Criar usuário</button></form></section>
<section class="card metric"><div class="label">Usuários cadastrados</div><div class="value">{{ $users->count() }}</div><div class="small muted">Ativos: {{ $users->where('active',true)->count() }}</div></section>
</div>
<section class="card" style="margin-top:18px"><h3>Contas</h3><div class="table-wrap"><table><thead><tr><th>Usuário</th><th>Perfil</th><th>Ativo</th><th>Alterar</th></tr></thead><tbody>
@foreach($users as $u)<tr><td><strong>{{ $u->name }}</strong><br><span class="small muted">@{{ $u->username }} · {{ $u->email }}</span></td><td>{{ $u->role }}</td><td>{{ $u->active?'Sim':'Não' }}</td><td>
<form method="post" action="{{ route('users.update',$u->username) }}" class="stack">@csrf @method('PUT')
<div class="form-grid"><input name="name" value="{{ $u->name }}" required><input type="email" name="email" value="{{ $u->email }}" required></div>
<div class="form-grid"><select name="role">@foreach(['user','head_comercial','admin'] as $r)<option value="{{ $r }}" @selected($u->role===$r)>{{ $r }}</option>@endforeach</select><input type="password" name="password" placeholder="Nova senha (opcional)"></div>
<label class="small"><input type="checkbox" name="active" value="1" @checked($u->active)> ativo</label><div class="actions"><button class="btn btn-sm">Salvar</button></form>
@if($u->username!==auth()->user()->username)<form method="post" action="{{ route('users.destroy',$u->username) }}" onsubmit="return confirm('Excluir esta conta?')">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">Excluir</button></form>@endif</div>
</td></tr>@endforeach
</tbody></table></div></section>
@endsection
