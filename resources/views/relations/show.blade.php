@extends('layouts.app')
@section('title','Mapa de Relações')
@section('topbar','Mapa de Relações · '.$p->name)
@section('content')
<div class="page-head"><div><h1>Mapa de Relações</h1><p>{{ $p->name }} · grafo navegável de fluxos e cards, inspirado em mapas de conhecimento.</p></div><div class="actions"><a class="btn" href="{{ route('projects.show',$p->_id) }}">Voltar ao projeto</a><button class="btn" id="graphFullscreen" type="button">⛶ Tela cheia</button></div></div>
@if(count($graph['broken']))<div class="alert alert-error">{{ count($graph['broken']) }} vínculo(s) quebrado(s) detectado(s).</div>@endif
<div class="graph-controls">
  <label>Visualização <select id="graphMode"><option value="flows">Somente fluxos</option><option value="cards">Cards de um fluxo</option><option value="combined">Fluxos + cards</option></select></label>
  <label>Fluxo <select id="graphFlow"><option value="">Todos</option>@foreach($graph['flows'] as $f)<option value="{{ $f->_id }}">{{ $f->name }}</option>@endforeach</select></label>
  <input id="graphSearch" type="search" placeholder="Buscar fluxo, card ou responsável">
  <button class="btn btn-sm" id="graphSearchBtn" type="button">Buscar</button><button class="btn btn-sm" id="graphIsolate" type="button">Isolar vizinhança</button><button class="btn btn-sm" id="graphExplode" type="button">Explodir</button><button class="btn btn-sm" id="graphPause" type="button">Pausar física</button><button class="btn btn-sm" id="graphReset" type="button">Restaurar</button>
</div>
<div class="graph-wrap graph-pro" id="relationGraph" data-url="{{ route('relations.data',$p->_id) }}"><canvas></canvas><div class="graph-legend" id="graphLegend">Duplo clique abre o item · roda = zoom · arraste o fundo = pan</div><div class="graph-detail" id="graphDetail" hidden></div></div>
@endsection
@push('scripts')<script src="{{ asset('assets/relations.js') }}?v=4.0.0"></script>@endpush
