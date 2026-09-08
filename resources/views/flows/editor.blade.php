@extends('layouts.app')
@section('title',$f->name)
@section('topbar','Editor de Processos e Projetos')
@section('content')
@php
  $isEditable = in_array($permission,['owner','editor','reviewer','approver'],true);
  $isReviewable = in_array($permission,['owner','reviewer','approver'],true);
  $isApprovable = in_array($permission,['owner','approver'],true);
  $flowUrls = $flowCatalog->mapWithKeys(fn($flow)=>[(string)$flow->_id=>route('flows.editor',$flow->_id)])->all();
  $flowOptions = $flowCatalog->map(fn($flow)=>[
    'id'=>(string)$flow->_id,'name'=>(string)$flow->name,'status'=>(string)$flow->workflow_status,
    'role'=>(string)($flow->project_role??''),'group'=>(string)($flow->project_group??''),'revision'=>(int)($flow->revision??1),
  ])->values()->all();
  $userOptions = $users->map(fn($user)=>['username'=>(string)$user->username,'name'=>(string)$user->name,'role'=>(string)$user->role])->values()->all();
  $versionOptions = $versions->map(fn($v)=>[
    'version'=>(int)$v->version,'reason'=>(string)$v->reason,'created_by'=>(string)$v->created_by,
    'created_at'=>$v->created_at?->format('d/m/Y H:i'),'diff_summary'=>$v->diff_summary??[],
  ])->values()->all();
  $commentOptions = $comments->map(fn($c)=>[
    'id'=>(string)$c->_id,'target_kind'=>(string)$c->target_kind,'target_id'=>(string)$c->target_id,
    'content'=>(string)$c->content,'author'=>(string)$c->author,'resolved'=>(bool)$c->resolved,
    'created_at'=>$c->created_at?->format('d/m/Y H:i'),
  ])->values()->all();
  $approvalOptions = $approvals->map(fn($a)=>[
    'from_status'=>(string)$a->from_status,'to_status'=>(string)$a->to_status,'action'=>(string)$a->action,
    'comment'=>(string)$a->comment,'created_by'=>(string)$a->created_by,'created_at'=>$a->created_at?->format('d/m/Y H:i'),
  ])->values()->all();
  $customTemplateOptions = $customTemplates->map(fn($t)=>[
    'id'=>(string)$t->_id,'name'=>(string)$t->name,'description'=>(string)$t->description,'category'=>(string)$t->category,
    'owner_username'=>(string)$t->owner_username,'organization'=>(bool)$t->organization,'builtin'=>false,
  ])->values()->all();
@endphp

<div class="pro-editor-page" id="proEditorPage">
  <div class="pro-contextbar">
    <div class="pro-context-left">
      <a class="btn btn-sm" data-nav href="{{ route('flows.index') }}">← Central</a>
      @if($project)<a class="btn btn-sm" data-nav href="{{ route('projects.show',$project->_id) }}">Projeto: {{ $project->name }}</a><a class="btn btn-sm" data-nav href="{{ route('relations.show',$project->_id) }}">Mapa</a>@endif
      <span class="badge status-{{ $f->workflow_status }}" id="workflowStatusBadge">{{ $f->workflow_status }}</span>
      <span class="small muted">v<span id="versionLabel">{{ $f->current_version }}</span> · rev <span id="revisionLabel">{{ $f->revision }}</span> · {{ $permission }}</span>
    </div>
    <div class="pro-presence" id="presenceBar"><span class="small muted">Colaboradores:</span><span id="presenceUsers" class="small muted">somente você</span></div>
  </div>

  @if($project && $flowCatalog->count())
  <div class="pro-flow-tabs" id="projectFlowTabs">
    @foreach($flowCatalog->take(10) as $flow)
      <a data-nav data-flow-tab="{{ $flow->_id }}" class="pro-flow-tab {{ (string)$flow->_id===(string)$f->_id?'active':'' }}" href="{{ route('flows.editor',$flow->_id) }}">
        {{ Str::limit($flow->name,34) }} <small>v{{ $flow->current_version }}</small>
      </a>
    @endforeach
  </div>
  @endif

  <header class="pro-toolbar" id="editorToolbar">
    <div class="pro-brand-block"><span class="brand-mark mini">F</span><div><strong id="flowTitleToolbar">{{ $f->name }}</strong><small id="editorStatus">Sem alterações pendentes</small></div></div>
    <div class="pro-toolbar-actions">
      <button type="button" class="btn btn-sm" data-action="undo" title="Desfazer (Ctrl+Z)">↶</button>
      <button type="button" class="btn btn-sm" data-action="redo" title="Refazer (Ctrl+Y)">↷</button><span class="toolbar-sep"></span>
      <button type="button" class="btn btn-sm" data-action="zoom-out">−</button>
      <button type="button" class="btn btn-sm" data-action="zoom-reset" id="zoomLabel">100%</button>
      <button type="button" class="btn btn-sm" data-action="zoom-in">+</button>
      <button type="button" class="btn btn-sm" data-action="fit">Enquadrar</button>
      <button type="button" class="btn btn-sm" data-action="fullscreen">⛶ Expandir</button><span class="toolbar-sep"></span>
      <button type="button" class="btn btn-sm" data-action="route-explorer">⌘ Rotas</button>
      <button type="button" class="btn btn-sm" data-action="focus-path">◎ Destacar</button>
      <button type="button" class="btn btn-sm btn-play" data-action="play">▶ Play</button>
      <button type="button" class="btn btn-sm" data-action="stop" disabled>■</button><span class="toolbar-sep"></span>
      <button type="button" class="btn btn-sm" data-action="layout">Organizar</button>
      <button type="button" class="btn btn-sm" data-action="validate">Validar</button>
      <button type="button" class="btn btn-sm" data-action="analytics">Indicadores</button>
      <button type="button" class="btn btn-sm" data-action="theme">Tema</button>
      <label class="btn btn-sm">Importar JSON<input id="importInput" type="file" accept=".json,application/json" hidden></label>
      <details class="pro-dropdown"><summary class="btn btn-sm">Downloads ▾</summary><div class="pro-dropdown-menu">
        <button type="button" data-export="json">JSON local</button><button type="button" data-export="svg-client">SVG do canvas</button><button type="button" data-export="png">PNG</button>
        <div class="divider"></div><button type="button" data-export="pdf">PDF do diagrama</button><button type="button" data-export="documentation-pdf">PDF documentação completa</button>
        <button type="button" data-export="html">HTML</button><button type="button" data-export="nodes-csv">CSV de cards</button><button type="button" data-export="raci-csv">CSV RACI</button><button type="button" data-export="bundle">Pacote ZIP completo</button>
      </div></details>
      @if($isEditable)<button type="button" class="btn btn-sm" data-action="save-draft">Salvar rascunho</button><button type="button" class="btn btn-sm btn-primary" data-action="save">Salvar versão</button>@endif
    </div>
  </header>

  <div class="pro-editor-shell">
    <aside class="pro-palette" id="palettePanel">
      <div class="pro-panel-head"><div><strong>Elementos</strong><small>Arraste ou clique para adicionar</small></div><button type="button" class="btn btn-sm" data-action="toggle-palette">‹</button></div>
      <div class="pro-palette-body">
        <input type="search" id="paletteSearch" placeholder="Buscar elemento">
        <div id="paletteItems" class="pro-palette-items"></div>
        <button type="button" class="btn" style="width:100%" data-action="add-lane">＋ Nova raia</button>
        <div class="pro-shortcuts small muted"><strong>Atalhos</strong><span>Ctrl/Shift + clique: seleção múltipla</span><span>Shift + arrastar: seleção por área</span><span>Ctrl+A: selecionar visíveis</span><span>Ctrl+D: duplicar</span><span>Ctrl+Z/Y: desfazer/refazer</span><span>Delete: excluir fora de campos</span><span>Espaço, meio ou direito: mover canvas</span></div>
      </div>
    </aside>

    <main class="pro-canvas-column">
      <div class="pro-canvas-statusbar">
        <span id="selectionLabel">Nenhum item selecionado</span><span id="focusStatus" class="muted"></span>
        <div class="pro-canvas-search"><input id="canvasSearch" type="search" placeholder="Buscar etapa, tag ou responsável"><button type="button" class="btn btn-sm" data-action="search-prev">‹</button><span id="searchCount" class="small muted"></span><button type="button" class="btn btn-sm" data-action="search-next">›</button></div>
        <div class="pro-canvas-options">
          <label>Visão <select id="viewMode"><option value="all">Completa</option><option value="executive">Executiva</option><option value="operational">Operacional</option><option value="technical">Técnica</option><option value="exceptions">Exceções</option><option value="selected-lane">Raia selecionada</option></select></label>
          <label>Linhas <select id="edgeVisibility"><option value="all">Todas</option><option value="selection">Somente seleção</option><option value="cross-lane">Entre raias</option><option value="none">Ocultar</option></select></label>
          <label>Traçado <select id="edgeRouting"><option value="smooth">Curvas suaves</option><option value="straight">Retas</option><option value="orthogonal">Ortogonal</option><option value="corridor-v2">Corredores inteligentes</option><option value="corridor">Corredores simples</option></select></label>
          <label>Velocidade <select id="playSpeed"><option value="1400">0,5×</option><option value="850" selected>1×</option><option value="450">2×</option></select></label>
          <label><input id="interactivePlay" type="checkbox" checked> Decisões</label><label><input id="showGrid" type="checkbox"> Grade</label><label><input id="snapGrid" type="checkbox"> Encaixar</label><label><input id="showMinimap" type="checkbox"> Minimapa</label><label><input id="autoFitLanes" type="checkbox"> Raias automáticas</label>
        </div>
      </div>
      <div class="pro-canvas-viewport" id="editorStage" tabindex="0">
        <div class="pro-canvas-world" id="flowWorld"><div class="pro-lane-layer" id="lanesLayer"></div><svg class="pro-edge-layer" id="edgeLayer" xmlns="http://www.w3.org/2000/svg"></svg><div class="pro-node-layer" id="nodesLayer"></div></div>
        <div class="pro-selection-box" id="selectionBox" hidden></div><canvas class="pro-minimap" id="minimap"></canvas>
        <div class="pro-playback-overlay" id="playbackOverlay" hidden><span class="playback-pulse"></span><strong id="playbackTitle">Reproduzindo fluxo</strong><small id="playbackProgress"></small></div>
        <div class="pro-empty-state" id="emptyState"><strong>Comece desenhando o processo</strong><span>Arraste um elemento da esquerda ou crie uma nova raia.</span></div>
      </div>
    </main>

    <aside class="pro-inspector" id="inspectorPanel">
      <div class="pro-panel-head"><div><strong>Propriedades e governança</strong><small id="propertiesCaption">Fluxo</small></div><button type="button" class="btn btn-sm" data-action="toggle-inspector">›</button></div>
      <div class="tabs compact-tabs" data-tabs="proInspector">
        <button class="active" data-tab="properties">Propriedades</button><button data-tab="governance">Governança</button><button data-tab="comments">Comentários</button><button data-tab="history">Versões</button><button data-tab="share">Acesso</button>
      </div>
      <div class="pro-inspector-scroll">
        <section class="tab-panel active" data-tab-panel-group="proInspector" data-tab-panel="properties"><div id="propertiesBody"></div></section>
        <section class="tab-panel" data-tab-panel-group="proInspector" data-tab-panel="governance">
          <div class="field"><label>Comentário da transição</label><textarea id="governanceComment"></textarea></div><div class="stack tight">
            @if($isEditable)<button class="btn" data-transition="submit_review">Enviar para revisão</button>@endif
            @if($isReviewable)<button class="btn" data-transition="request_changes">Solicitar alterações</button>@endif
            @if($isApprovable)<button class="btn" data-transition="approve">Aprovar</button><button class="btn btn-primary" data-transition="publish">Publicar</button><button class="btn" data-transition="archive">Arquivar</button><button class="btn" data-transition="reopen">Reabrir</button>@endif
          </div><div class="divider"></div><div id="approvalHistory" class="stack tight small"></div>
        </section>
        <section class="tab-panel" data-tab-panel-group="proInspector" data-tab-panel="comments">
          <div class="field"><label>Novo comentário</label><textarea id="newComment"></textarea></div><button class="btn btn-primary btn-sm" data-action="add-comment">Adicionar ao item selecionado</button><div class="divider"></div><label class="small"><input type="checkbox" id="showResolvedComments"> Mostrar resolvidos</label><div id="commentsList" class="stack tight small"></div>
        </section>
        <section class="tab-panel" data-tab-panel-group="proInspector" data-tab-panel="history">
          <div class="pro-version-compare"><select id="compareLeft"></select><span>×</span><select id="compareRight"></select><button class="btn btn-sm" data-action="compare-versions">Comparar</button></div><div id="versionsList" class="stack tight small"></div><div class="divider"></div>
          @if($isEditable)<button class="btn" data-action="load-draft" {{ $draft?'':'disabled' }}>Carregar rascunho manual</button><button class="btn" data-action="discard-draft" {{ $draft?'':'disabled' }}>Descartar rascunho</button>@endif
          <div class="divider"></div><form method="post" action="{{ route('flows.duplicate',$f->_id) }}">@csrf<button class="btn" type="submit">Duplicar fluxo</button></form>
          <form method="post" action="{{ route('flows.destroy',$f->_id) }}" onsubmit="return confirm('Excluir permanentemente este fluxo e seu histórico?')" style="margin-top:8px">@csrf @method('DELETE')<button class="btn btn-danger" type="submit">Excluir fluxo</button></form>
        </section>
        <section class="tab-panel" data-tab-panel-group="proInspector" data-tab-panel="share">
          <div class="field"><label>Visibilidade</label><select id="visibilitySelect"><option value="private">Privado</option><option value="organization">Organização</option></select></div><div id="collaboratorsEditor"></div>
          @if($permission==='owner')<button class="btn btn-primary btn-sm" data-action="save-sharing">Salvar compartilhamento</button>@endif
          <div class="divider"></div><h3>Templates</h3><div class="field"><label>Nome do template</label><input id="templateName"></div><div class="field"><label>Categoria</label><input id="templateCategory" value="Geral"></div><div class="field"><label>Descrição</label><textarea id="templateDescription"></textarea></div>@if($isEditable)<button class="btn btn-sm" data-action="create-template">Salvar fluxo como template</button>@endif
          <div class="divider"></div><div id="templatesList" class="stack tight small"></div>
        </section>
      </div>
    </aside>
  </div>

  <div class="pro-toasts" id="toastContainer"></div>
  <div class="pro-modal-backdrop" id="genericModal" hidden><section class="pro-modal"><header><strong id="modalTitle">Detalhes</strong><button type="button" class="btn btn-sm" data-action="close-modal">×</button></header><div id="modalBody" class="pro-modal-body"></div><footer><button type="button" class="btn" data-action="close-modal">Fechar</button></footer></section></div>
  <div class="pro-modal-backdrop" id="navigationModal" hidden><section class="pro-modal narrow"><header><strong>Alterações ainda não estão no banco</strong></header><div class="pro-modal-body"><p>Há alterações em andamento. Escolha o que fazer antes de mudar de página.</p><small class="muted">Salvar rascunho envia o estado atual ao MongoDB sem criar uma versão formal.</small></div><footer class="actions"><button class="btn" data-action="nav-cancel">Continuar editando</button><button class="btn" data-action="nav-leave">Sair sem sincronizar</button>@if($isEditable)<button class="btn btn-primary" data-action="nav-save">Salvar rascunho e sair</button>@endif</footer></section></div>
</div>
@endsection

@push('scripts')
<script>
window.FLUXOS_BOOT={
  flowId:@json((string)$f->_id), revision:@json((int)$f->revision), version:@json((int)$f->current_version), status:@json((string)$f->workflow_status), permission:@json($permission),
  editable:@json($isEditable), reviewable:@json($isReviewable), approvable:@json($isApprovable),
  username:@json((string)auth()->user()->username), userName:@json((string)auth()->user()->name), theme:@json(data_get(auth()->user()->produto_tools_preferences,'ui_theme','light')),
  document:@json($f->document), draft:@json($draft?->document), draftBaseRevision:@json($draft?->base_revision),
  project:@json($project?->toArray()), projectId:@json((string)($f->project_id??'')), flowCatalog:@json($flowOptions), flowUrls:@json($flowUrls), users:@json($userOptions),
  versions:@json($versionOptions), comments:@json($commentOptions), approvals:@json($approvalOptions), presence:@json($presence), analysis:@json($analysis),
  templates:{builtIn:@json($builtInTemplates),custom:@json($customTemplateOptions)}, focusNode:@json((string)request('focus_node','')),
  visibility:@json((string)($f->visibility??'private')), collaborators:@json($f->collaborators??[]),
  urls:{
    save:@json(route('flows.save',$f->_id)), resolveConflict:@json(route('flows.conflict.resolve',$f->_id)), draft:@json(route('flows.draft.save',$f->_id)), discardDraft:@json(route('flows.draft.discard',$f->_id)), transition:@json(route('flows.transition',$f->_id)), comment:@json(route('flows.comment',$f->_id)), import:@json(route('flows.import',$f->_id)),
    validate:@json(route('flows.validate',$f->_id)), analyze:@json(route('flows.analyze',$f->_id)), sharing:@json(route('flows.sharing',$f->_id)), presence:@json(route('flows.presence',$f->_id)), createTemplate:@json(route('flows.templates.store',$f->_id)), createFromTemplate:@json(route('templates.create-flow')),
    version:@json(url('/api/processos/'.$f->_id.'/versoes/__VERSION__')), compareVersions:@json(route('flows.versions.compare',$f->_id)), restoreVersion:@json(url('/api/processos/'.$f->_id.'/restaurar/__VERSION__')), deleteTemplate:@json(url('/api/templates/__ID__')), export:@json(url('/api/processos/'.$f->_id.'/exportar/__FORMAT__'))
  }
};
</script>
<script src="{{ asset('assets/flow-editor.js') }}?v=4.1.1"></script>
@endpush
