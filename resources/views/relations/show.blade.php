@extends('layouts.app')
@section('title','Mapa de Relações')
@section('topbar','Mapa de Relações · '.$p->name)
@section('content')
<div class="page-head"><div><h1>Mapa de Relações</h1><p>{{ $p->name }} · arraste os nós, use a roda do mouse para zoom e arraste o fundo para navegar.</p></div><a class="btn" href="{{ route('projects.show',$p->_id) }}">Voltar ao projeto</a></div>
@if(count($graph['broken']))<div class="alert alert-error">{{ count($graph['broken']) }} vínculo(s) quebrado(s) detectado(s).</div>@endif
<div class="graph-wrap" id="relationGraph" data-url="{{ route('relations.data',$p->_id) }}"><canvas></canvas><div class="graph-legend">Fluxos = nós · vínculos de subprocesso = arestas</div></div>
@endsection
@push('scripts')<script src="{{ asset('assets/relations.js') }}"></script>@endpush
