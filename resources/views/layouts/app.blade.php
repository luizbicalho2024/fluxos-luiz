<!doctype html>
<html lang="pt-BR" data-theme="{{ data_get(auth()->user()?->produto_tools_preferences, 'ui_theme', 'light') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title','Fluxos Luiz') · Fluxos Luiz</title>
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}">
    <script>
      (function(){const t=localStorage.getItem('fluxos-theme')||document.documentElement.dataset.theme||'light';document.documentElement.dataset.theme=t})();
    </script>
    @stack('head')
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <a class="brand" href="{{ route('dashboard') }}"><span class="brand-mark">F</span><span>Fluxos Luiz</span></a>
        <nav class="nav">
            <a class="{{ request()->routeIs('dashboard')?'active':'' }}" href="{{ route('dashboard') }}">Visão geral</a>
            <a class="{{ request()->routeIs('flows.*')?'active':'' }}" href="{{ route('flows.index') }}">Central de Processos</a>
            <a class="{{ request()->routeIs('projects.*')?'active':'' }}" href="{{ route('projects.index') }}">Gestão de Projetos</a>
            @if(auth()->user()?->isAdmin())
                <a class="{{ request()->routeIs('users.*')?'active':'' }}" href="{{ route('users.index') }}">Gestão de Acesso</a>
            @endif
        </nav>
        <div class="sidebar-footer">
            <div class="userbox"><strong>{{ auth()->user()->name }}</strong>@{{ auth()->user()->username }} · {{ auth()->user()->role }}</div>
            <div class="actions" style="margin-top:10px">
                <button class="btn btn-sm" type="button" id="themeToggle">Tema</button>
                <form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-sm" type="submit">Sair</button></form>
            </div>
        </div>
    </aside>
    <main class="main">
        <header class="topbar">
            <div><strong>@yield('topbar','Fluxos Luiz')</strong></div>
            <div class="small muted">{{ now()->format('d/m/Y H:i') }}</div>
        </header>
        <div class="content">
            @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
            @if($errors->any())<div class="alert alert-error">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
            @yield('content')
        </div>
    </main>
</div>
<script>
document.getElementById('themeToggle')?.addEventListener('click',async()=>{
  const next=document.documentElement.dataset.theme==='dark'?'light':'dark';
  document.documentElement.dataset.theme=next;localStorage.setItem('fluxos-theme',next);
  try{await fetch(@json(route('preferences.theme')),{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({theme:next})});}catch(_e){}
});
document.querySelectorAll('[data-tabs]').forEach(group=>{
  group.querySelectorAll('[data-tab]').forEach(btn=>btn.addEventListener('click',()=>{
    const id=btn.dataset.tab;
    group.querySelectorAll('[data-tab]').forEach(x=>x.classList.toggle('active',x===btn));
    document.querySelectorAll(`[data-tab-panel-group="${group.dataset.tabs}"]`).forEach(p=>p.classList.toggle('active',p.dataset.tabPanel===id));
  }));
});
</script>
@stack('scripts')
</body>
</html>
