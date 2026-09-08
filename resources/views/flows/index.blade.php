@extends('layouts.app')
@section('title','Central de Processos')
@section('topbar','Central de Processos')
@section('content')
<div class="page-head"><div><h1>Central de Processos</h1><p>Crie, revise, compartilhe e publique fluxos de forma governada.</p></div></div>
<div class="grid grid-3">
<section class="card"><h3>Novo processo</h3><form method="post" action="{{ route('flows.store') }}" class="stack">@csrf
<div class="field"><label>Nome</label><input name="name" required placeholder="Ex.: Aprovação comercial"></div>
<button class="btn btn-primary" type="submit">Criar processo</button></form></section>
<section class="card metric"><div class="label">Total acessível</div><div class="value">{{ $flows->count() }}</div><div class="small muted">Inclui processos próprios, compartilhados e da organização.</div></section>
<section class="card metric"><div class="label">Publicados</div><div class="value">{{ $flows->where('workflow_status','published')->count() }}</div><div class="small muted">Versões formalmente publicadas.</div></section>
</div>
<section class="card" style="margin-top:18px"><h3>Processos</h3>
<div class="table-wrap"><table><thead><tr><th>Processo</th><th>Status</th><th>Projeto</th><th>Proprietário</th><th>Versão</th><th>Atualizado</th><th></th></tr></thead><tbody>
@forelse($flows as $f)<tr>
<td><strong>{{ $f->name }}</strong><br><span class="small muted">{{ $f->description }}</span></td>
<td><span class="badge status-{{ $f->workflow_status }}">{{ $f->workflow_status }}</span></td><td>{{ $f->project_id ?: 'Avulso' }}</td>
<td>{{ $f->owner_username }}</td><td>v{{ $f->current_version }} · rev {{ $f->revision }}</td>
<td>{{ optional($f->updated_at)->format('d/m/Y H:i') }}</td><td><a class="btn btn-sm" href="{{ route('flows.editor',$f->_id) }}">Abrir</a></td>
</tr>@empty<tr><td colspan="7" class="muted">Nenhum processo encontrado.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
