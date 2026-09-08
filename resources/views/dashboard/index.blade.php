@extends('layouts.app')
@section('title','Visão geral')
@section('topbar','Visão geral')
@section('content')
<div class="page-head"><div><h1>Visão geral</h1><p>Indicadores dos projetos e processos aos quais você possui acesso.</p></div>
<div class="actions"><a class="btn btn-primary" href="{{ route('flows.index') }}">Abrir Central de Processos</a></div></div>
<div class="grid grid-5">
 @foreach([['Projetos',$stats['projects']],['Processos',$stats['flows']],['Rascunhos',$stats['drafts']],['Em revisão',$stats['in_review']],['Publicados',$stats['published']]] as $m)
 <div class="card metric"><div class="label">{{ $m[0] }}</div><div class="value">{{ $m[1] }}</div></div>
 @endforeach
</div>
<div class="grid grid-2" style="margin-top:18px">
 <section class="card"><h3>Processos recentes</h3>
   <div class="stack">
   @forelse($flows->take(8) as $flow)
    <a href="{{ route('flows.editor',$flow->_id) }}" style="display:flex;justify-content:space-between;gap:14px"><span><strong>{{ $flow->name }}</strong><br><span class="small muted">rev. {{ $flow->revision }} · v{{ $flow->current_version }}</span></span><span class="badge status-{{ $flow->workflow_status }}">{{ $flow->workflow_status }}</span></a>
   @empty <span class="muted">Nenhum processo acessível.</span>@endforelse
   </div>
 </section>
 <section class="card"><h3>Projetos recentes</h3>
   <div class="stack">
   @forelse($projects->take(8) as $p)
    <a href="{{ route('projects.show',$p->_id) }}" style="display:flex;justify-content:space-between;gap:14px"><span><strong>{{ $p->name }}</strong><br><span class="small muted">{{ $p->code }}</span></span><span class="badge">{{ $p->status }}</span></a>
   @empty <span class="muted">Nenhum projeto acessível.</span>@endforelse
   </div>
 </section>
</div>
<section class="card" style="margin-top:18px"><h3>Atividade recente</h3>
<div class="table-wrap"><table><thead><tr><th>Quando</th><th>Usuário</th><th>Ação</th></tr></thead><tbody>
@forelse($recent as $r)<tr><td>{{ optional($r->timestamp)->format('d/m/Y H:i:s') ?: $r->timestamp }}</td><td>{{ $r->user }}</td><td>{{ $r->action }}</td></tr>
@empty<tr><td colspan="3" class="muted">Sem eventos.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
