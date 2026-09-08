@extends('layouts.app')
@section('title','Gestão de Projetos')
@section('topbar','Gestão de Projetos')
@section('content')
<div class="page-head"><div><h1>Gestão de Projetos</h1><p>Agrupe visão executiva, operacional e fluxos auxiliares em uma mesma iniciativa.</p></div></div>
<div class="grid grid-3">
<section class="card"><h3>Novo projeto</h3><form method="post" action="{{ route('projects.store') }}" class="stack">@csrf
<div class="field"><label>Nome</label><input name="name" required></div><div class="field"><label>Descrição</label><textarea name="description"></textarea></div>
<button class="btn btn-primary" type="submit">Criar projeto</button></form></section>
@foreach($projects as $p)
<a class="card" href="{{ route('projects.show',$p->_id) }}"><div class="actions" style="justify-content:space-between"><span class="badge">{{ $p->status }}</span><span class="small muted">{{ $p->code }}</span></div><h3 style="margin-top:14px">{{ $p->name }}</h3><p class="muted">{{ \Illuminate\Support\Str::limit($p->description,140) }}</p><div class="small muted">Release atual: {{ $p->current_release ?: '—' }}</div></a>
@endforeach
</div>
@endsection
