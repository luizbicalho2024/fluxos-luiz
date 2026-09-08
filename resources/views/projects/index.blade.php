@extends('layouts.app')
@section('title','Gestão de Projetos')
@section('topbar','Gestão de Projetos')
@section('content')
<div class="page-head"><div><h1>Projetos e fluxos vinculados</h1><p>Workspaces com visão executiva, processos operacionais, subprocessos, busca global, releases e pacotes portáveis.</p></div></div>
<div class="grid grid-3">
<section class="card"><h3>Novo projeto</h3><form method="post" action="{{ route('projects.store') }}" class="stack">@csrf
<div class="field"><label>Nome</label><input name="name" required placeholder="Ex.: SIGYO Modular"></div><div class="field"><label>Código</label><input name="code" placeholder="SIGYO"></div><div class="field"><label>Descrição</label><textarea name="description"></textarea></div>
<button class="btn btn-primary" type="submit">Criar projeto</button></form></section>
<section class="card"><h3>Importar project.zip</h3><p class="small muted">Restaura um pacote completo com project.json e os JSONs de cada fluxo.</p><form method="post" enctype="multipart/form-data" action="{{ route('projects.import.bundle') }}" class="stack">@csrf<div class="field"><label>Pacote ZIP</label><input type="file" name="bundle" accept=".zip,application/zip" required></div><label class="small"><input type="checkbox" name="preserve_ids" value="1"> preservar IDs quando possível</label><button class="btn">Importar pacote</button></form></section>
<section class="card"><h3>Criar projeto por vários JSONs</h3><p class="small muted">Importa diversos fluxos de uma vez e repara decisões inconsistentes sem inventar regras de negócio.</p><form method="post" enctype="multipart/form-data" action="{{ route('projects.import.jsons') }}" class="stack">@csrf<div class="field"><label>Nome do projeto</label><input name="name" required></div><div class="field"><label>Descrição</label><textarea name="description"></textarea></div><div class="field"><label>Fluxos JSON</label><input type="file" name="files[]" accept=".json,application/json" multiple required></div><button class="btn">Importar JSONs</button></form></section>
</div>
<h2 style="margin-top:24px">Seus projetos</h2>
<div class="grid grid-3">
@forelse($projects as $p)
<a class="card" href="{{ route('projects.show',$p->_id) }}"><div class="actions" style="justify-content:space-between"><span class="badge">{{ $p->status }}</span><span class="small muted">{{ $p->code }}</span></div><h3 style="margin-top:14px">{{ $p->name }}</h3><p class="muted">{{ \Illuminate\Support\Str::limit($p->description,140) }}</p><div class="small muted">Release atual: {{ $p->current_release ?: '—' }} · {{ count((array)($p->members??[])) }} participante(s)</div></a>
@empty<div class="card"><span class="muted">Nenhum projeto ainda. Crie ou importe um pacote acima.</span></div>@endforelse
</div>
@endsection
