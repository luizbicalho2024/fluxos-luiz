@extends('layouts.app')
@section('title',$p->name)
@section('topbar','Projeto · '.$p->name)
@section('content')
<div class="page-head"><div><div class="actions"><span class="badge">{{ $p->status }}</span><span class="small muted">{{ $p->code }}</span></div><h1 style="margin-top:8px">{{ $p->name }}</h1><p>{{ $p->description }}</p></div>
<div class="actions"><a class="btn" href="{{ route('relations.show',$p->_id) }}">Mapa de Relações</a></div></div>
<div class="grid grid-5">
@foreach([['Fluxos',$analysis['flow_count']],['Cards',$analysis['node_count']],['Conexões',$analysis['edge_count']],['Vínculos quebrados',$analysis['broken_count']],['Qualidade',$analysis['quality_score'].'%']] as $m)
<div class="card metric"><div class="label">{{ $m[0] }}</div><div class="value">{{ $m[1] }}</div></div>@endforeach
</div>
<div class="tabs" data-tabs="project"><button class="active" data-tab="flows">Fluxos</button><button data-tab="settings">Configurações</button><button data-tab="members">Participantes</button><button data-tab="quality">Qualidade</button><button data-tab="releases">Releases</button></div>

<section class="tab-panel active" data-tab-panel-group="project" data-tab-panel="flows">
<div class="grid grid-2">
<div class="card"><h3>Fluxos do projeto</h3><div class="stack">@forelse($flows as $f)
<div style="display:flex;justify-content:space-between;gap:12px"><span><strong>{{ $f->name }}</strong><br><span class="small muted">{{ $f->project_role ?: 'subprocess' }} · ordem {{ $f->project_order }}</span></span><a class="btn btn-sm" href="{{ route('flows.editor',$f->_id) }}">Editar</a></div>
@empty<span class="muted">Nenhum fluxo vinculado.</span>@endforelse</div></div>
<div class="card"><h3>Adicionar fluxo existente</h3><form method="post" action="{{ route('projects.assign',$p->_id) }}" class="stack">@csrf
<div class="field"><label>Fluxo</label><select name="flow_id" required><option value="">Selecione</option>@foreach($available as $f)<option value="{{ $f->_id }}">{{ $f->name }}</option>@endforeach</select></div>
<div class="form-grid"><div class="field"><label>Papel</label><select name="role"><option value="executive">Visão executiva</option><option value="operational">Visão operacional</option><option value="subprocess" selected>Fluxo auxiliar</option><option value="support">Apoio</option></select></div><div class="field"><label>Ordem</label><input type="number" name="order" value="0" min="0"></div></div>
<div class="field"><label>Grupo</label><input name="group"></div><button class="btn btn-primary">Vincular fluxo</button></form></div>
</div></section>

<section class="tab-panel" data-tab-panel-group="project" data-tab-panel="settings">
<div class="card"><form method="post" action="{{ route('projects.update',$p->_id) }}" class="stack">@csrf @method('PUT')
<div class="form-grid"><div class="field"><label>Nome</label><input name="name" value="{{ $p->name }}" required></div><div class="field"><label>Status</label><select name="status">@foreach(['draft','in_review','published','archived'] as $s)<option value="{{ $s }}" @selected($p->status===$s)>{{ $s }}</option>@endforeach</select></div></div>
<div class="field"><label>Descrição</label><textarea name="description">{{ $p->description }}</textarea></div>
<div class="field"><label>Visibilidade</label><select name="visibility"><option value="private" @selected($p->visibility==='private')>Privado</option><option value="organization" @selected($p->visibility==='organization')>Organização</option></select></div>
<button class="btn btn-primary">Salvar projeto</button></form><div class="divider"></div>
<form method="post" action="{{ route('projects.destroy',$p->_id) }}" onsubmit="return confirm('Excluir projeto? Esta ação não pode ser desfeita.')" class="actions">@csrf @method('DELETE')
<label class="small"><input type="checkbox" name="delete_flows" value="1"> excluir também os fluxos</label><button class="btn btn-danger">Excluir projeto</button></form></div>
</section>

<section class="tab-panel" data-tab-panel-group="project" data-tab-panel="members">
<div class="card"><h3>Participantes</h3><form method="post" action="{{ route('projects.members',$p->_id) }}">@csrf
<div class="table-wrap"><table><thead><tr><th>Usuário</th><th>Nível no projeto</th></tr></thead><tbody>
@foreach($users as $user) @if($user->username!==$p->owner_username)
@php($existing=collect((array)$p->members)->firstWhere('username',$user->username))
<tr><td><strong>{{ $user->name }}</strong><br><span class="small muted">@{{ $user->username }}</span></td><td><select name="members[{{ $user->username }}]"><option value="">Sem acesso específico</option>@foreach(['viewer','editor','reviewer','approver'] as $level)<option value="{{ $level }}" @selected(($existing['level']??'')===$level)>{{ $level }}</option>@endforeach</select></td></tr>
@endif @endforeach
</tbody></table></div><button class="btn btn-primary" style="margin-top:12px">Salvar participantes</button></form></div>
</section>

<section class="tab-panel" data-tab-panel-group="project" data-tab-panel="quality">
<div class="card"><h3>Análise de qualidade</h3>
@if($analysis['broken_count'])<div class="alert alert-error">{{ $analysis['broken_count'] }} vínculo(s) quebrado(s). Corrija antes de publicar uma release.</div>@endif
@if(($analysis['cycle_count'] ?? 0)>0)<div class="alert alert-error">{{ $analysis['cycle_count'] }} ciclo(s) entre fluxos detectado(s). Revise os subprocessos para evitar navegação circular.</div>@endif
<div class="table-wrap"><table><thead><tr><th>Fluxo</th><th>Qualidade</th><th>Cards</th><th>Arestas</th><th>Problemas</th></tr></thead><tbody>
@foreach($analysis['quality_rows'] as $row)<tr><td>{{ $row['name'] }}</td><td>{{ $row['quality_score'] }}%</td><td>{{ $row['node_count'] }}</td><td>{{ $row['edge_count'] }}</td><td>{{ $row['issue_count'] }}</td></tr>@endforeach
</tbody></table></div></div></section>

<section class="tab-panel" data-tab-panel-group="project" data-tab-panel="releases">
<div class="grid grid-2"><div class="card"><h3>Criar release consolidada</h3><form method="post" action="{{ route('projects.release',$p->_id) }}" class="stack">@csrf
<div class="field"><label>Nome</label><input name="name" placeholder="Opcional"></div><div class="field"><label>Notas</label><textarea name="notes"></textarea></div><button class="btn btn-primary">Criar release</button></form></div>
<div class="card"><h3>Histórico</h3><div class="stack">@forelse($releases as $r)<div><strong>v{{ $r->version }} · {{ $r->name }}</strong><br><span class="small muted">{{ optional($r->created_at)->format('d/m/Y H:i') }} · qualidade {{ $r->quality_score }}%</span></div>@empty<span class="muted">Nenhuma release criada.</span>@endforelse</div></div></div>
</section>
@endsection
