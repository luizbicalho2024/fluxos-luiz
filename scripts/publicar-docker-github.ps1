$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$RepoUrl = 'https://github.com/luizbicalho2024/fluxos-luiz.git'
$RepoName = 'fluxos-luiz'
$DefaultWorkDir = Join-Path ([Environment]::GetFolderPath('MyDocuments')) $RepoName
$Utf8NoBom = New-Object System.Text.UTF8Encoding($false)

function Write-Step([string]$Text) {
    Write-Host "`n============================================================" -ForegroundColor Cyan
    Write-Host " $Text" -ForegroundColor Cyan
    Write-Host "============================================================" -ForegroundColor Cyan
}

function Test-Command([string]$Name) {
    return [bool](Get-Command $Name -ErrorAction SilentlyContinue)
}

function New-HexSecret([int]$Bytes = 24) {
    $buffer = New-Object byte[] $Bytes
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($buffer) } finally { $rng.Dispose() }
    return ([System.BitConverter]::ToString($buffer)).Replace('-', '').ToLowerInvariant()
}

function New-LaravelKey {
    $buffer = New-Object byte[] 32
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($buffer) } finally { $rng.Dispose() }
    return 'base64:' + [Convert]::ToBase64String($buffer)
}

function Set-DotEnvValue([string]$Content, [string]$Name, [string]$Value) {
    $escaped = [Regex]::Escape($Name)
    if ($Content -match "(?m)^$escaped=") {
        return [Regex]::Replace($Content, "(?m)^$escaped=.*$", "$Name=$Value")
    }
    return $Content.TrimEnd() + "`r`n$Name=$Value`r`n"
}

function Get-DotEnvValue([string]$Content, [string]$Name) {
    $escaped = [Regex]::Escape($Name)
    $m = [Regex]::Match($Content, "(?m)^$escaped=(.*)$")
    if (-not $m.Success) { return $null }
    return $m.Groups[1].Value.Trim().Trim('"').Trim("'")
}

function Find-ProjectSource {
    $candidates = New-Object System.Collections.Generic.List[string]
    $candidates.Add((Get-Location).Path)
    $candidates.Add((Join-Path $HOME 'Downloads\fluxos-luiz'))
    $candidates.Add((Join-Path $HOME 'Downloads\fluxos-luiz-laravel-mongo'))
    $candidates.Add((Join-Path $HOME 'Desktop\fluxos-luiz'))

    foreach ($candidate in $candidates) {
        if ($candidate -and (Test-Path (Join-Path $candidate 'composer.json')) -and (Test-Path (Join-Path $candidate 'docker-compose.yml'))) {
            return (Resolve-Path $candidate).Path
        }
    }

    $downloads = Join-Path $HOME 'Downloads'
    if (Test-Path $downloads) {
        $zip = Get-ChildItem $downloads -File -Filter 'fluxos-luiz*.zip' -ErrorAction SilentlyContinue |
            Sort-Object LastWriteTime -Descending |
            Select-Object -First 1
        if ($zip) {
            $extractRoot = Join-Path $env:TEMP ('fluxos-luiz-source-' + [Guid]::NewGuid().ToString('N'))
            New-Item -ItemType Directory -Path $extractRoot -Force | Out-Null
            Expand-Archive -Path $zip.FullName -DestinationPath $extractRoot -Force
            $composer = Get-ChildItem $extractRoot -File -Filter 'composer.json' -Recurse -ErrorAction SilentlyContinue |
                Where-Object { Test-Path (Join-Path $_.Directory.FullName 'docker-compose.yml') } |
                Select-Object -First 1
            if ($composer) { return $composer.Directory.FullName }
        }
    }

    throw @"
Nao encontrei os arquivos do projeto.
Baixe o ZIP 'fluxos-luiz-laravel-mongo.zip' fornecido pelo ChatGPT e deixe-o na pasta Downloads,
ou extraia o ZIP e execute este bloco dentro da pasta extraida.
"@
}

function Copy-Project([string]$Source, [string]$Destination) {
    $skipDirs = @('.git', 'vendor', 'node_modules')
    $skipFiles = @('.env')
    Get-ChildItem -LiteralPath $Source -Recurse -Force | ForEach-Object {
        $relative = $_.FullName.Substring($Source.Length).TrimStart([char[]]'\/')
        if (-not $relative) { return }
        $parts = $relative -split '[\\/]'
        if ($parts | Where-Object { $skipDirs -contains $_ }) { return }
        if (-not $_.PSIsContainer -and $skipFiles -contains $_.Name) { return }
        $target = Join-Path $Destination $relative
        if ($_.PSIsContainer) {
            New-Item -ItemType Directory -Path $target -Force | Out-Null
        } else {
            New-Item -ItemType Directory -Path (Split-Path $target -Parent) -Force | Out-Null
            Copy-Item -LiteralPath $_.FullName -Destination $target -Force
        }
    }
}

Write-Step '1/8 - Validando ferramentas'
if (-not (Test-Command 'git')) {
    throw 'Git nao foi encontrado. Instale o Git for Windows e execute novamente.'
}
if (-not (Test-Command 'docker')) {
    $dockerDesktop = Join-Path $env:ProgramFiles 'Docker\Docker\Docker Desktop.exe'
    $dockerCliDir = Join-Path $env:ProgramFiles 'Docker\Docker\resources\bin'
    if (Test-Path (Join-Path $dockerCliDir 'docker.exe')) {
        $env:Path = $dockerCliDir + ';' + $env:Path
    } elseif (-not (Test-Path $dockerDesktop)) {
        throw 'Docker Desktop nao foi encontrado. Instale o Docker Desktop e execute novamente.'
    }
}

$SourceDir = Find-ProjectSource
Write-Host "Fonte da versao Laravel: $SourceDir"

Write-Step '2/8 - Preparando repositorio fluxos-luiz'
$WorkDir = $DefaultWorkDir

# Se a fonte estiver exatamente no destino e ainda nao for um clone Git,
# preserve uma copia temporaria antes de preparar o repositorio.
if ((Test-Path $WorkDir) -and -not (Test-Path (Join-Path $WorkDir '.git'))) {
    $sourceResolved = (Resolve-Path $SourceDir).Path.TrimEnd([char[]]'\/')
    $workResolved = (Resolve-Path $WorkDir).Path.TrimEnd([char[]]'\/')
    if ($sourceResolved -ieq $workResolved) {
        $tempSource = Join-Path $env:TEMP ('fluxos-luiz-source-' + [Guid]::NewGuid().ToString('N'))
        Copy-Item -LiteralPath $SourceDir -Destination $tempSource -Recurse -Force
        $SourceDir = $tempSource
        Write-Host "Fonte temporaria preservada em: $SourceDir" -ForegroundColor Yellow
    }
}
if (Test-Path (Join-Path $WorkDir '.git')) {
    Set-Location $WorkDir
    git remote set-url origin $RepoUrl
    git fetch origin --prune
    $remoteMain = git ls-remote --heads origin main
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao consultar o repositorio remoto.' }
    if ($remoteMain) {
        git checkout main 2>$null
        if ($LASTEXITCODE -ne 0) { git checkout -B main origin/main }
        git pull --rebase origin main
        if ($LASTEXITCODE -ne 0) { throw 'Falha ao atualizar a branch main local.' }
    }
} else {
    if (Test-Path $WorkDir) {
        $backup = "$WorkDir.backup.$(Get-Date -Format 'yyyyMMdd-HHmmss')"
        Move-Item $WorkDir $backup
        Write-Host "Pasta anterior preservada em: $backup" -ForegroundColor Yellow
    }
    New-Item -ItemType Directory -Path (Split-Path $WorkDir -Parent) -Force | Out-Null
    git clone $RepoUrl $WorkDir
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao clonar fluxos-luiz.' }
    Set-Location $WorkDir
    git checkout -B main
}

$sameSourceAndWork = $false
try {
    $sameSourceAndWork = ((Resolve-Path $SourceDir).Path.TrimEnd([char[]]'\/') -ieq (Resolve-Path $WorkDir).Path.TrimEnd([char[]]'\/'))
} catch {}
if (-not $sameSourceAndWork) {
    Copy-Project -Source $SourceDir -Destination $WorkDir
}
Set-Location $WorkDir

Write-Step '3/8 - Configurando ambiente local'
$EnvPath = Join-Path $WorkDir '.env'
if (-not (Test-Path $EnvPath)) {
    $envContent = [IO.File]::ReadAllText((Join-Path $WorkDir '.env.example'))
    $mongoPassword = New-HexSecret 24
    $adminPassword = 'Adm!' + (New-HexSecret 16)
    $appKey = New-LaravelKey
    $envContent = Set-DotEnvValue $envContent 'APP_KEY' $appKey
    $envContent = Set-DotEnvValue $envContent 'MONGO_PASSWORD' $mongoPassword
    $envContent = Set-DotEnvValue $envContent 'ADMIN_PASSWORD' $adminPassword
    $envContent = Set-DotEnvValue $envContent 'DB_URI' "mongodb://fluxos_admin:$mongoPassword@mongo:27017/fluxos_luiz?authSource=admin"
    [IO.File]::WriteAllText($EnvPath, $envContent, $Utf8NoBom)
    Write-Host 'Novo .env local criado.' -ForegroundColor Green
} else {
    $envContent = [IO.File]::ReadAllText($EnvPath)
    $mongoPassword = Get-DotEnvValue $envContent 'MONGO_PASSWORD'
    $adminPassword = Get-DotEnvValue $envContent 'ADMIN_PASSWORD'
    Write-Host '.env existente preservado.' -ForegroundColor Green
}

Write-Step '4/8 - Garantindo Docker Desktop ativo'
$dockerReady = $false
try {
    docker info *> $null
    if ($LASTEXITCODE -eq 0) { $dockerReady = $true }
} catch {}

if (-not $dockerReady) {
    $dockerDesktop = Join-Path $env:ProgramFiles 'Docker\Docker\Docker Desktop.exe'
    if (-not (Test-Path $dockerDesktop)) { throw 'Docker Desktop nao foi encontrado.' }
    Start-Process $dockerDesktop | Out-Null
    for ($i=0; $i -lt 90; $i++) {
        Start-Sleep -Seconds 2
        try {
            docker info *> $null
            if ($LASTEXITCODE -eq 0) { $dockerReady = $true; break }
        } catch {}
    }
}
if (-not $dockerReady) { throw 'Docker Desktop nao ficou disponivel.' }
docker compose version
if ($LASTEXITCODE -ne 0) { throw 'Docker Compose v2 nao esta disponivel.' }

Write-Step '5/8 - Construindo e subindo Laravel + MongoDB'
docker compose down --remove-orphans
docker compose up -d --build
if ($LASTEXITCODE -ne 0) { throw 'Falha no docker compose up.' }
docker compose ps

Write-Step '6/8 - Validando aplicacao'
$healthy = $false
for ($i=0; $i -lt 60; $i++) {
    Start-Sleep -Seconds 2
    try {
        $response = Invoke-WebRequest -UseBasicParsing -Uri 'http://localhost:8080/up' -TimeoutSec 5
        if ($response.StatusCode -eq 200) { $healthy = $true; break }
    } catch {}
}
if (-not $healthy) {
    docker compose logs --tail=200 app
    docker compose logs --tail=120 mongo
    throw 'A aplicacao nao respondeu com sucesso em http://localhost:8080/up.'
}

docker compose exec -T app php artisan about --only=environment 2>$null
Write-Host 'Aplicacao validada em http://localhost:8080' -ForegroundColor Green

Write-Step '7/8 - Preparando autenticacao GitHub'
if (Test-Command 'gh') {
    gh auth status *> $null
    if ($LASTEXITCODE -ne 0) {
        gh auth login --hostname github.com --git-protocol https --web
        if ($LASTEXITCODE -ne 0) { throw 'Falha na autenticacao do GitHub CLI.' }
    }
    gh auth setup-git
}

if (-not (git config user.name)) { git config user.name 'luizbicalho2024' }
if (-not (git config user.email)) { git config user.email 'luizbicalho2024@users.noreply.github.com' }

git add -A
$changes = git status --porcelain
if ($changes) {
    git commit -m 'feat: migrar Produto Tools para Laravel MongoDB Docker'
    if ($LASTEXITCODE -ne 0) { throw 'Falha ao criar commit.' }
} else {
    Write-Host 'Nenhuma alteracao nova para commit.' -ForegroundColor Yellow
}

Write-Step '8/8 - Publicando na main'
git branch -M main
git push -u origin main
if ($LASTEXITCODE -ne 0) {
    throw @"
Falha no git push.
Se o GitHub pedir autenticacao, execute 'gh auth login' ou autentique o Git Credential Manager e rode novamente.
O Docker local ja permanece funcionando.
"@
}

$envContent = [IO.File]::ReadAllText($EnvPath)
$adminUser = Get-DotEnvValue $envContent 'ADMIN_USERNAME'
$adminPassword = Get-DotEnvValue $envContent 'ADMIN_PASSWORD'

Write-Host "`n============================================================" -ForegroundColor Green
Write-Host ' CONCLUIDO' -ForegroundColor Green
Write-Host '============================================================' -ForegroundColor Green
Write-Host "Projeto local : $WorkDir"
Write-Host 'Aplicacao     : http://localhost:8080'
Write-Host "Usuario admin : $adminUser"
Write-Host "Senha admin   : $adminPassword" -ForegroundColor Yellow
Write-Host 'Repositorio   : https://github.com/luizbicalho2024/fluxos-luiz'
Write-Host "`nA senha acima esta apenas no .env local, que esta ignorado pelo Git."
