param(
    [string]$ProjectDir = (Join-Path ([Environment]::GetFolderPath('MyDocuments')) 'fluxos-luiz'),
    [string]$ZipPath = '',
    [switch]$SkipGitPush,
    [switch]$SkipTests
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$RepoUrl = 'https://github.com/luizbicalho2024/fluxos-luiz.git'
$Version = '4.1.0'
$Utf8NoBom = New-Object System.Text.UTF8Encoding($false)

function Step([string]$Text) {
    Write-Host ''
    Write-Host '============================================================' -ForegroundColor Cyan
    Write-Host " $Text" -ForegroundColor Cyan
    Write-Host '============================================================' -ForegroundColor Cyan
}

function Has-Command([string]$Name) {
    return [bool](Get-Command $Name -ErrorAction SilentlyContinue)
}

function Ensure-Success([string]$Message) {
    if ($LASTEXITCODE -ne 0) { throw $Message }
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

function Set-EnvValue([string]$Content,[string]$Name,[string]$Value) {
    $escaped = [Regex]::Escape($Name)
    if ($Content -match "(?m)^$escaped=") {
        return [Regex]::Replace($Content,"(?m)^$escaped=.*$","$Name=$Value")
    }
    return $Content.TrimEnd() + "`r`n$Name=$Value`r`n"
}

function Get-EnvValue([string]$Content,[string]$Name) {
    $m=[Regex]::Match($Content,"(?m)^$([Regex]::Escape($Name))=(.*)$")
    if (-not $m.Success) { return $null }
    return $m.Groups[1].Value.Trim().Trim('"').Trim("'")
}

function Find-Zip {
    if ($ZipPath) {
        if (-not (Test-Path $ZipPath)) { throw "ZIP informado nao existe: $ZipPath" }
        return (Resolve-Path $ZipPath).Path
    }

    $locations=@((Get-Location).Path,(Join-Path $HOME 'Downloads'),(Join-Path $HOME 'Desktop'))
    foreach($location in $locations) {
        if (-not (Test-Path $location)) { continue }
        $zip=Get-ChildItem -Path $location -File -ErrorAction SilentlyContinue |
            Where-Object { $_.Name -like 'fluxos-luiz-paridade-completa*.zip' -or $_.Name -like 'fluxos-luiz*v4.1.0*.zip' } |
            Sort-Object LastWriteTime -Descending |
            Select-Object -First 1
        if ($zip) { return $zip.FullName }
    }
    throw @"
Nao encontrei o ZIP da versao 4.1.0.
Baixe 'fluxos-luiz-paridade-completa-v4.1.0.zip' e deixe em Downloads.
"@
}

function Find-SourceRoot([string]$ExtractRoot) {
    $composer=Get-ChildItem -Path $ExtractRoot -File -Filter 'composer.json' -Recurse -ErrorAction SilentlyContinue |
        Where-Object { Test-Path (Join-Path $_.Directory.FullName 'docker-compose.yml') } |
        Select-Object -First 1
    if (-not $composer) { throw 'Nao encontrei composer.json + docker-compose.yml dentro do ZIP.' }
    return $composer.Directory.FullName
}

function Copy-Project([string]$Source,[string]$Destination) {
    $skipDirs=@('.git','vendor','node_modules')
    $skipFiles=@('.env')
    Get-ChildItem -LiteralPath $Source -Recurse -Force | ForEach-Object {
        $relative=$_.FullName.Substring($Source.Length).TrimStart([char[]]'\/')
        if (-not $relative) { return }
        $parts=$relative -split '[\\/]'
        if ($parts | Where-Object { $skipDirs -contains $_ }) { return }
        if (-not $_.PSIsContainer -and $skipFiles -contains $_.Name) { return }
        $target=Join-Path $Destination $relative
        if ($_.PSIsContainer) {
            New-Item -ItemType Directory -Path $target -Force | Out-Null
        } else {
            New-Item -ItemType Directory -Path (Split-Path $target -Parent) -Force | Out-Null
            Copy-Item -LiteralPath $_.FullName -Destination $target -Force
        }
    }
}

function Ensure-Docker {
    if (-not (Has-Command 'docker')) {
        $dockerCli=Join-Path $env:ProgramFiles 'Docker\Docker\resources\bin'
        if (Test-Path (Join-Path $dockerCli 'docker.exe')) {
            $env:Path="$dockerCli;$env:Path"
        }
    }
    if (-not (Has-Command 'docker')) { throw 'Docker Desktop nao foi encontrado.' }

    $ready=$false
    try { docker info *> $null; if ($LASTEXITCODE -eq 0) { $ready=$true } } catch {}
    if (-not $ready) {
        $desktop=Join-Path $env:ProgramFiles 'Docker\Docker\Docker Desktop.exe'
        if (-not (Test-Path $desktop)) { throw 'Docker Desktop nao foi encontrado.' }
        Start-Process $desktop | Out-Null
        for($i=0;$i -lt 90;$i++) {
            Start-Sleep -Seconds 2
            try { docker info *> $null; if ($LASTEXITCODE -eq 0) { $ready=$true; break } } catch {}
        }
    }
    if (-not $ready) { throw 'Docker Desktop nao ficou disponivel.' }
    docker compose version
    Ensure-Success 'Docker Compose v2 nao esta disponivel.'
}

function Backup-Code([string]$Directory,[string]$BackupRoot) {
    if (-not (Test-Path $Directory)) { return $null }
    $temp=Join-Path $env:TEMP ('fluxos-luiz-backup-' + [Guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $temp -Force | Out-Null
    Copy-Project -Source $Directory -Destination $temp
    if (Test-Path (Join-Path $Directory '.env')) { Copy-Item (Join-Path $Directory '.env') (Join-Path $temp '.env') -Force }
    $archive=Join-Path $BackupRoot ("fluxos-luiz-codigo-antes-v$Version-$(Get-Date -Format 'yyyyMMdd-HHmmss').zip")
    Compress-Archive -Path (Join-Path $temp '*') -DestinationPath $archive -Force
    Remove-Item $temp -Recurse -Force -ErrorAction SilentlyContinue
    return $archive
}

function Try-MongoBackup([string]$BackupRoot) {
    $running=docker ps --format '{{.Names}}' 2>$null
    if ($running -notcontains 'fluxos-luiz-mongo') { return $null }
    $stamp=Get-Date -Format 'yyyyMMdd-HHmmss'
    $inside="/tmp/fluxos-luiz-$stamp.archive.gz"
    $outside=Join-Path $BackupRoot "fluxos-luiz-mongo-$stamp.archive.gz"
    try {
        docker exec fluxos-luiz-mongo sh -lc "command -v mongodump >/dev/null 2>&1 && mongodump --archive='$inside' --gzip"
        if ($LASTEXITCODE -ne 0) { return $null }
        docker cp "fluxos-luiz-mongo:$inside" $outside | Out-Null
        docker exec fluxos-luiz-mongo rm -f $inside | Out-Null
        if (Test-Path $outside) { return $outside }
    } catch {}
    return $null
}

Step '1/10 - Validando ferramentas e pacote'
if (-not (Has-Command 'git')) { throw 'Git for Windows nao foi encontrado.' }
Ensure-Docker
$Zip=Find-Zip
Write-Host "Pacote: $Zip" -ForegroundColor Green
$extract=Join-Path $env:TEMP ('fluxos-luiz-update-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $extract -Force | Out-Null
Expand-Archive -Path $Zip -DestinationPath $extract -Force
$Source=Find-SourceRoot $extract
Write-Host "Fonte extraida: $Source"

Step '2/10 - Preparando repositorio local'
if (-not (Test-Path (Join-Path $ProjectDir '.git'))) {
    if (Test-Path $ProjectDir) {
        $old="$ProjectDir.antigo.$(Get-Date -Format 'yyyyMMdd-HHmmss')"
        Move-Item $ProjectDir $old
        Write-Host "Pasta anterior preservada em: $old" -ForegroundColor Yellow
    }
    New-Item -ItemType Directory -Path (Split-Path $ProjectDir -Parent) -Force | Out-Null
    git clone $RepoUrl $ProjectDir
    Ensure-Success 'Falha ao clonar fluxos-luiz.'
}
Set-Location $ProjectDir
git remote set-url origin $RepoUrl

$downloads=Join-Path $HOME 'Downloads'
if (-not (Test-Path $downloads)) { $downloads=$ProjectDir }
$codeBackup=Backup-Code -Directory $ProjectDir -BackupRoot $downloads
if ($codeBackup) { Write-Host "Backup de codigo: $codeBackup" -ForegroundColor Green }

$localChanges=git status --porcelain
if ($localChanges) {
    git stash push -u -m "backup automatico antes da paridade $Version"
    Ensure-Success 'Falha ao preservar alteracoes locais no stash.'
    Write-Host 'Alteracoes locais anteriores foram preservadas em git stash.' -ForegroundColor Yellow
}

git fetch origin --prune
Ensure-Success 'Falha no git fetch.'
git checkout main
if ($LASTEXITCODE -ne 0) { git checkout -B main origin/main }
git pull --rebase origin main
Ensure-Success 'Falha ao atualizar a main antes da migracao.'

Step '3/10 - Backup adicional do MongoDB'
$mongoBackup=Try-MongoBackup -BackupRoot $downloads
if ($mongoBackup) {
    Write-Host "Backup MongoDB: $mongoBackup" -ForegroundColor Green
} else {
    Write-Host 'mongodump nao estava disponivel/ativo; o volume Docker sera preservado integralmente.' -ForegroundColor Yellow
}

Step '4/10 - Preservando configuracao local e aplicando 4.1.0'
$EnvPath=Join-Path $ProjectDir '.env'
$EnvContent=$null
if (Test-Path $EnvPath) {
    $EnvContent=[IO.File]::ReadAllText($EnvPath)
    Write-Host '.env existente carregado e sera preservado.' -ForegroundColor Green
}

Copy-Project -Source $Source -Destination $ProjectDir

if ($EnvContent -ne $null) {
    [IO.File]::WriteAllText($EnvPath,$EnvContent,$Utf8NoBom)
} elseif (Test-Path (Join-Path $ProjectDir '.env.example')) {
    $EnvContent=[IO.File]::ReadAllText((Join-Path $ProjectDir '.env.example'))
    $mongoPassword=New-HexSecret 24
    $adminPassword='Adm!' + (New-HexSecret 16)
    $EnvContent=Set-EnvValue $EnvContent 'APP_KEY' (New-LaravelKey)
    $EnvContent=Set-EnvValue $EnvContent 'MONGO_PASSWORD' $mongoPassword
    $EnvContent=Set-EnvValue $EnvContent 'ADMIN_PASSWORD' $adminPassword
    [IO.File]::WriteAllText($EnvPath,$EnvContent,$Utf8NoBom)
    Write-Host 'Novo .env criado porque nao havia configuracao anterior.' -ForegroundColor Yellow
}

if (-not (Test-Path (Join-Path $ProjectDir 'VERSION'))) { throw 'Arquivo VERSION ausente apos a copia.' }
$installedVersion=(Get-Content (Join-Path $ProjectDir 'VERSION') -Raw).Trim()
if ($installedVersion -ne $Version) { throw "Pacote inesperado. VERSION=$installedVersion; esperado=$Version" }

Step '5/10 - Preservando volume e reconstruindo containers'
$volumeBefore=docker volume ls --format '{{.Name}}' | Where-Object { $_ -match 'fluxos.*mongo|fluxos_luiz_mongo' }
if ($volumeBefore) { Write-Host ('Volumes Mongo existentes: ' + ($volumeBefore -join ', ')) -ForegroundColor Green }

# IMPORTANTE: nunca usar -v aqui. O banco existente deve permanecer.
docker compose down --remove-orphans
Ensure-Success 'Falha ao parar os containers atuais.'
docker compose up -d --build
Ensure-Success 'Falha ao reconstruir/subir os containers.'
docker compose ps

Step '6/10 - Validando Laravel e MongoDB'
$healthy=$false
for($i=0;$i -lt 75;$i++) {
    Start-Sleep -Seconds 2
    try {
        $r=Invoke-WebRequest -UseBasicParsing -Uri 'http://localhost:8080/up' -TimeoutSec 5
        if ($r.StatusCode -eq 200) { $healthy=$true; break }
    } catch {}
}
if (-not $healthy) {
    docker compose logs --tail=220 app
    docker compose logs --tail=160 mongo
    throw 'A aplicacao nao respondeu em http://localhost:8080/up.'
}

docker compose exec -T app php artisan app:ensure-mongo-indexes
Ensure-Success 'Falha ao garantir indices MongoDB.'
docker compose exec -T app php artisan route:list --except-vendor *> $null
Ensure-Success 'Falha ao carregar as rotas Laravel.'
Write-Host 'Laravel + MongoDB responderam corretamente.' -ForegroundColor Green

Step '7/10 - Executando regressao da paridade'
if (-not $SkipTests) {
    docker compose exec -T app php artisan test
    if ($LASTEXITCODE -ne 0) {
        docker compose logs --tail=180 app
        throw 'A suite de testes falhou. O push foi bloqueado; o backup e o banco permanecem preservados.'
    }
} else {
    Write-Host 'Testes ignorados por -SkipTests.' -ForegroundColor Yellow
}

Step '8/10 - Validando recursos criticos do editor'
$editorJs=Join-Path $ProjectDir 'public\assets\flow-editor.js'
$editorBlade=Join-Path $ProjectDir 'resources\views\flows\editor.blade.php'
$contracts=@(
    'startMarquee','duplicateSelection','alignSelection','distributeSelection','startPlayback',
    'route-explorer','focus-path','compare-versions','resolveConflict','conflictCopy','conflictOverwrite',
    'linkedFlowId','linkedFlowEntryNodeId','linkedFlowExitNodeId','preferredEdgeId','beforeunload'
)
$combined=[IO.File]::ReadAllText($editorJs) + "`n" + [IO.File]::ReadAllText($editorBlade)
foreach($contract in $contracts) {
    if (-not $combined.Contains($contract)) { throw "Contrato de paridade ausente: $contract" }
}
Write-Host 'Contratos principais do editor 4.1.0 validados.' -ForegroundColor Green

Step '9/10 - Commit e publicacao no GitHub'
if (-not (git config user.name)) { git config user.name 'luizbicalho2024' }
if (-not (git config user.email)) { git config user.email 'luizbicalho2024@users.noreply.github.com' }

git add -A
$changes=git status --porcelain
if ($changes) {
    git commit -m 'feat: completar paridade funcional do Produto Tools no Laravel'
    Ensure-Success 'Falha ao criar commit.'
} else {
    Write-Host 'Nenhuma alteracao nova para commit.' -ForegroundColor Yellow
}

if (-not $SkipGitPush) {
    if (Has-Command 'gh') {
        gh auth status *> $null
        if ($LASTEXITCODE -ne 0) {
            gh auth login --hostname github.com --git-protocol https --web
            Ensure-Success 'Falha ao autenticar no GitHub.'
        }
        gh auth setup-git *> $null
    }
    git push -u origin main
    Ensure-Success 'Falha no git push. O ambiente local ja foi atualizado e continua funcionando.'
} else {
    Write-Host 'Push ignorado por -SkipGitPush.' -ForegroundColor Yellow
}

Step '10/10 - Resultado final'
$EnvContent=[IO.File]::ReadAllText($EnvPath)
$adminUser=Get-EnvValue $EnvContent 'ADMIN_USERNAME'
Write-Host "Versao       : $installedVersion" -ForegroundColor Green
Write-Host "Projeto       : $ProjectDir"
Write-Host 'Aplicacao     : http://localhost:8080'
Write-Host "Usuario admin : $adminUser"
Write-Host 'MongoDB       : volume Docker preservado; nenhuma operacao down -v foi executada.' -ForegroundColor Green
if ($codeBackup) { Write-Host "Backup codigo : $codeBackup" }
if ($mongoBackup) { Write-Host "Backup Mongo  : $mongoBackup" }
if ($localChanges) { Write-Host 'Alteracoes anteriores: preservadas em git stash.' -ForegroundColor Yellow }
Write-Host 'Repositorio    : https://github.com/luizbicalho2024/fluxos-luiz'
Write-Host ''
Write-Host 'Fluxos Luiz 4.1.0 atualizado com paridade funcional do Produto Tools.' -ForegroundColor Green

Remove-Item $extract -Recurse -Force -ErrorAction SilentlyContinue
