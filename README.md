# Fluxos Luiz — Laravel + MongoDB

Migração funcional do **Produto Tools 3.2.6/3.2.6.1** de Streamlit/Python para **PHP Laravel 12 + MongoDB**, preparada para execução local no **Docker Desktop**.

## O que foi preservado

- autenticação sobre a coleção `users`, com `username`, `hashed_password`, `role`, `active` e preferência de tema;
- papéis globais `user`, `head_comercial` e `admin`;
- Gestão de Acesso, com proteção do último administrador ativo;
- Central de Processos;
- editor visual com raias, cards, decisões, subprocessos, conexões, zoom, enquadramento, importação e exportação JSON;
- importação resiliente: decisão com menos de duas saídas é convertida em atividade, sem inventar regra de negócio;
- rascunho explícito por usuário, sem autosave oculto;
- controle otimista por `revision`, evitando sobrescrita silenciosa em edição concorrente;
- histórico de versões e hash SHA-256 do documento;
- governança `draft → in_review → approved → published`, além de arquivar/reabrir;
- permissões `viewer`, `editor`, `reviewer`, `approver` e proprietário;
- comentários vinculados ao fluxo/card/conexão;
- Gestão de Projetos com múltiplos fluxos e papéis `executive`, `operational`, `subprocess`, `support`;
- vínculos entre subprocessos por `linkedFlowId`, `linkedFlowEntryNodeId` e `linkedFlowExitNodeId`;
- análise de vínculos quebrados, fluxos órfãos, ciclos e qualidade;
- mapa interativo de relações;
- releases consolidadas com snapshot de revisão/versão/hash de cada fluxo;
- tema claro/escuro persistido no perfil do usuário.

## Arquitetura

```text
Browser
  │
  ▼
Laravel 12 / Blade / JavaScript
  │  porta do host 8080
  │
  ├── autenticação / sessão / CSRF / rate limit
  ├── projetos, fluxos, governança e API JSON do editor
  └── MongoDB Laravel Driver
          │
          ▼
      MongoDB 8.0
      host 127.0.0.1:27018
      rede interna Docker: mongo:27017
```

## Coleções MongoDB

A migração mantém os nomes do Produto Tools:

```text
users
activity_logs
produto_tools_projects
produto_tools_project_members
produto_tools_project_releases
produto_tools_project_release_flows
produto_tools_flowcharts
produto_tools_flowchart_versions
produto_tools_flowchart_drafts
produto_tools_flowchart_comments
produto_tools_flowchart_approvals
produto_tools_flowchart_templates
produto_tools_flowchart_presence
```

Os índices são criados de forma idempotente no start do container.

## Requisitos locais

- Windows 10/11;
- Docker Desktop com Linux Containers;
- Git;
- acesso ao GitHub para publicar no repositório.

PHP, Composer e MongoDB **não precisam estar instalados no Windows**.

## Subir manualmente

```powershell
Copy-Item .env.example .env
# Preencha APP_KEY, MONGO_PASSWORD e ADMIN_PASSWORD.
docker compose up -d --build
docker compose ps
```

Aplicação: <http://localhost:8080>

MongoDB exposto apenas localmente: `127.0.0.1:27018`.

## Inicialização automática

O entrypoint executa, a cada subida:

```bash
php artisan optimize:clear
php artisan app:ensure-mongo-indexes
php artisan app:bootstrap-admin
php artisan serve --host=0.0.0.0 --port=8000
```

`app:bootstrap-admin` cria o usuário inicial somente se ele ainda não existir. Se já existir, não redefine sua senha automaticamente.

## Segurança local

- `.env` não é versionado;
- senha do MongoDB e senha do administrador são geradas pelo script PowerShell;
- MongoDB só é publicado em `127.0.0.1:27018`;
- sessão Laravel com regeneração após login;
- CSRF nas mutações web;
- rate limit no login;
- senhas com bcrypt;
- último administrador ativo não pode ser removido/desativado;
- atualização de fluxos usa `revision` para detectar conflito de concorrência.

## Compatibilidade com dados do Produto Tools

O modelo Laravel mantém os nomes de coleções e os principais campos do sistema Python. Para usar uma base MongoDB existente, altere `DB_URI`/`DB_DATABASE` no `.env`. O campo de senha esperado continua sendo `hashed_password`, e hashes bcrypt existentes são aceitos pelo Laravel.

Antes de apontar uma base de produção para esta versão, faça backup e valide uma cópia da base.

## Estrutura relevante

```text
app/
├── Console/Commands/       # índices e bootstrap do administrador
├── Http/Controllers/
├── Http/Middleware/
├── Models/                 # modelos MongoDB
└── Services/
    ├── FlowDocumentService.php
    ├── FlowRepository.php
    └── ProjectRepository.php
public/assets/
├── app.css
├── flow-editor.js
└── relations.js
resources/views/
routes/web.php
docker-compose.yml
Dockerfile
scripts/publicar-docker-github.ps1
```

## Publicação

O script `scripts/publicar-docker-github.ps1` foi feito para:

1. localizar este projeto (ou o ZIP baixado);
2. clonar/atualizar `https://github.com/luizbicalho2024/fluxos-luiz.git`;
3. gerar `.env` com segredos locais quando necessário;
4. iniciar o Docker Desktop se necessário;
5. executar `docker compose up -d --build`;
6. validar `/up` e o estado dos containers;
7. fazer commit e `push` da versão para `main`.

O `.env` permanece somente na máquina local.
