<!doctype html>
<html lang="pt-BR" data-theme="light">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Acesso · Fluxos Luiz</title><link rel="stylesheet" href="{{ asset('assets/app.css') }}">
<script>(function(){document.documentElement.dataset.theme=localStorage.getItem('fluxos-theme')||'light'})()</script>
</head>
<body class="login-page">
<div class="login-card">
    <div class="brand"><span class="brand-mark">F</span><span>Fluxos Luiz</span></div>
    <h1>Acesso à plataforma</h1>
    <p>Modelagem, governança e publicação de processos.</p>
    @if($errors->any())<div class="alert alert-error">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
    <form method="post" action="{{ route('login.submit') }}" class="stack">@csrf
        <div class="field"><label>Usuário</label><input name="username" value="{{ old('username') }}" autocomplete="username" required autofocus></div>
        <div class="field"><label>Senha</label><input type="password" name="password" autocomplete="current-password" required></div>
        <button class="btn btn-primary" type="submit">Entrar</button>
    </form>
</div>
</body></html>
