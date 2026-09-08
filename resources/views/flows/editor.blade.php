@extends('layouts.app')
@section('title',$f->name)
@section('topbar','Editor de Fluxos · '.$f->name)
@section('content')
<div class="page-head" style="margin-bottom:10px">
  <div>
    <div class="actions"><span class="badge status-{{ $f->workflow_status }}">{{ $f->workflow_status }}</span><span class="small muted">v{{ $f->current_version }} · rev {{ $f->revision }} · {{ $permission }}</span></div>
    <h1 style="margin-top:8px">{{ $f->name }}</h1>
  </div>
  <div class="actions"><a class="btn" href="{{ route('flows.index') }}">Central de Processos</a>
    @if($f->project_id)<a class="btn" href="{{ route('projects.show',$f->project_id) }}">Projeto</a>@endif
  </div>
</div>

<div class="toolbar">
  <select id="nodeTypeSelect" class="btn">
    <option value="task">Atividade</option><option value="start">Início</option><option value="end">Fim</option>
    <option value="decision">Decisão</option><option value="subprocess">Subprocesso</option><option value="event">Evento</option>
    <option value="wait">Espera</option><option value="document">Documento</option><option value="api">API</option><option value="note">Nota</option>
  </select>
  <button class="btn" id="addNodeBtn" type="button">Adicionar card</button>
  <button class="btn" id="addLaneBtn" type="button">Adicionar raia</button>
  <span class="sep"></span>
  <button class="btn" id="connectBtn" type="button">Conectar</button>
  <button class="btn btn-danger" id="deleteSelectedBtn" type="button">Excluir seleção</button>
  <span class="sep"></span>
  <button class="btn" id="fitBtn" type="button">Enquadrar</button>
  <button class="btn" id="exportBtn" type="button">Exportar JSON</button>
  <label class="btn">Importar JSON<input id="importInput" type="file" accept=".json,application/json" hidden></label>
  <span class="sep"></span>
  <button class="btn" id="saveDraftBtn" type="button">Salvar rascunho</button>
  @if($draft)<button class="btn" id="loadDraftBtn" type="button">Carregar rascunho</button><button class="btn" id="discardDraftBtn" type="button">Descartar rascunho</button>@endif
  <button class="btn btn-primary" id="saveBtn" type="button">Salvar versão</button>
  <span class="editor-status" id="editorStatus">Sem alterações</span>
</div>

<div class="editor-shell">
  <div class="editor-stage-wrap">
    <div class="editor-stage" id="editorStage">
      <div class="flow-world" id="flowWorld">
        <svg class="edge-layer" id="edgeLayer"></svg>
        <div id="lanesLayer"></div>
        <div id="nodesLayer"></div>
      </div>
    </div>
  </div>
  <aside class="editor-side">
    <div class="tabs" data-tabs="editorSide">
      <button class="active" data-tab="properties">Propriedades</button>
      <button data-tab="governance">Governança</button>
      <button data-tab="comments">Comentários</button>
      <button data-tab="history">Histórico</button>
    </div>

    <section class="tab-panel active" data-tab-panel-group="editorSide" data-tab-panel="properties">
      <h3>Documento</h3>
      <div class="field"><label>Nome do fluxo</label><input id="flowName"></div>
      <div class="field"><label>Descrição</label><textarea id="flowDescription"></textarea></div>
      <div class="divider"></div>
      <div id="nodeInspectorEmpty" class="muted small">Selecione um card para editar suas propriedades.</div>
      <div id="nodeInspector" hidden>
        <h3>Card selecionado</h3>
        <div class="field"><label>Tipo</label><select id="nodeType">@foreach(['start','end','task','decision','subprocess','event','wait','document','api','note'] as $t)<option>{{ $t }}</option>@endforeach</select></div>
        <div class="field"><label>Título</label><input id="nodeLabel"></div>
        <div class="field"><label>Descrição</label><textarea id="nodeDescription"></textarea></div>
        <div class="field"><label>Responsável</label><input id="nodeOwner"></div>
        <div class="field"><label>Raia</label><select id="nodeLane"></select></div>
        <div class="field"><label>Criticidade</label><select id="nodeCriticality"><option value="low">Baixa</option><option value="medium">Média</option><option value="high">Alta</option><option value="critical">Crítica</option></select></div>
        <div class="field"><label>Tags (separadas por vírgula)</label><input id="nodeTags"></div>
        <div class="field"><label>Fluxo vinculado</label><input id="nodeLinkedFlow" placeholder="flow_xxx"></div>
        <div class="field"><label>Nó de entrada vinculado</label><input id="nodeLinkedEntry"></div>
        <div class="field"><label>Nó de saída vinculado</label><input id="nodeLinkedExit"></div>
      </div>
      <div class="divider"></div>
      <h3>Conexão selecionada</h3>
      <div id="edgeInspectorEmpty" class="muted small">Clique em uma conexão para editar o rótulo/condição.</div>
      <div id="edgeInspector" hidden>
        <div class="field"><label>Rótulo</label><input id="edgeLabel"></div>
        <div class="field"><label>Condição</label><input id="edgeCondition"></div>
        <button class="btn btn-danger btn-sm" id="deleteEdgeBtn" type="button">Excluir conexão</button>
      </div>
    </section>

    <section class="tab-panel" data-tab-panel-group="editorSide" data-tab-panel="governance">
      <h3>Governança</h3>
      <p class="small muted">Status atual: <strong>{{ $f->workflow_status }}</strong>. As ações disponíveis dependem da sua permissão.</p>
      <div class="field"><label>Comentário da transição</label><textarea id="governanceComment"></textarea></div>
      <div class="stack">
        @if(in_array($permission,['owner','editor','reviewer','approver']))<button class="btn" data-transition="submit_review">Enviar para revisão</button>@endif
        @if(in_array($permission,['owner','reviewer','approver']))<button class="btn" data-transition="request_changes">Solicitar alterações</button>@endif
        @if(in_array($permission,['owner','approver']))<button class="btn" data-transition="approve">Aprovar</button><button class="btn btn-primary" data-transition="publish">Publicar</button><button class="btn" data-transition="archive">Arquivar</button><button class="btn" data-transition="reopen">Reabrir</button>@endif
      </div>
      <div class="divider"></div><h3>Histórico de governança</h3><div class="stack small">
      @forelse($approvals as $a)<div><strong>{{ $a->from_status }} → {{ $a->to_status }}</strong><br><span class="muted">{{ $a->created_by }} · {{ optional($a->created_at)->format('d/m/Y H:i') }}</span>@if($a->comment)<br>{{ $a->comment }}@endif</div>
      @empty<span class="muted">Nenhuma transição registrada.</span>@endforelse
      </div>
    </section>

    <section class="tab-panel" data-tab-panel-group="editorSide" data-tab-panel="comments">
      <h3>Comentários</h3>
      <div class="field"><label>Novo comentário</label><textarea id="newComment"></textarea></div>
      <button class="btn btn-primary btn-sm" id="addCommentBtn" type="button">Adicionar comentário</button>
      <div class="divider"></div>
      <div class="stack small" id="commentsList">
      @forelse($comments as $c)<div data-comment="{{ $c->_id }}"><strong>@{{ $c->author }}</strong> @if($c->resolved)<span class="badge">resolvido</span>@endif<br><span>{{ $c->content }}</span><br><span class="muted">{{ optional($c->created_at)->format('d/m/Y H:i') }} · {{ $c->target_kind }} {{ $c->target_id }}</span></div>
      @empty<span class="muted">Nenhum comentário.</span>@endforelse
      </div>
    </section>

    <section class="tab-panel" data-tab-panel-group="editorSide" data-tab-panel="history">
      <h3>Versões</h3>
      <div class="stack small">
      @forelse($versions as $v)<div><strong>v{{ $v->version }}</strong> · {{ $v->reason }}<br><span class="muted">{{ $v->created_by }} · {{ optional($v->created_at)->format('d/m/Y H:i') }}</span></div>
      @empty<span class="muted">Nenhuma versão.</span>@endforelse
      </div>
      <div class="divider"></div>
      <form method="post" action="{{ route('flows.duplicate',$f->_id) }}">@csrf<button class="btn" type="submit">Duplicar fluxo</button></form>
      <form method="post" action="{{ route('flows.destroy',$f->_id) }}" onsubmit="return confirm('Excluir permanentemente este fluxo e seu histórico?')" style="margin-top:10px">@csrf @method('DELETE')<button class="btn btn-danger" type="submit">Excluir fluxo</button></form>
    </section>
  </aside>
</div>
@endsection

@push('scripts')
<script>
window.FLUXOS_BOOT={
  flowId:@json((string)$f->_id),
  revision:@json((int)$f->revision),
  permission:@json($permission),
  document:@json($f->document),
  draft:@json($draft?->document),
  draftBaseRevision:@json($draft?->base_revision),
  urls:{
    save:@json(route('flows.save',$f->_id)),
    draft:@json(route('flows.draft.save',$f->_id)),
    discardDraft:@json(route('flows.draft.discard',$f->_id)),
    transition:@json(route('flows.transition',$f->_id)),
    comment:@json(route('flows.comment',$f->_id)),
    import:@json(route('flows.import',$f->_id))
  }
};
</script>
<script src="{{ asset('assets/flow-editor.js') }}"></script>
@endpush
