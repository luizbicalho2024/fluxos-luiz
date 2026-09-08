# Mapeamento da migração Produto Tools → Fluxos Luiz

| Produto Tools (Python/Streamlit) | Fluxos Luiz (Laravel) |
|---|---|
| `login_app.py`, `core/auth.py` | `AuthController`, modelo Mongo `User`, guard `web` |
| `database.py` | modelos MongoDB + `EnsureMongoIndexes` |
| `pages/1_Gestao_de_Acesso.py` | `/acessos` |
| `pages/2_Central_de_Processos.py` | `/processos` |
| `pages/3_Gestao_de_Projetos.py` | `/projetos` |
| `pages/4_Mapa_de_Relacoes.py` | `/mapa-relacoes/{project}` + Canvas JS |
| `pages/5_Editor_de_Fluxos.py` | `/processos/{id}/editor` |
| `schemas/flowchart_schema.py` | `FlowDocumentService` |
| `services/flowchart_repository.py` | `FlowRepository` |
| `services/project_repository.py` | `ProjectRepository` |
| Streamlit custom component | Blade + `public/assets/flow-editor.js` |

## Decisões técnicas

- Nenhuma gravação automática de rascunho: o usuário escolhe **Salvar rascunho** ou **Salvar versão**.
- Ao salvar uma versão publicada depois de editá-la, o status volta a `draft`, preservando a publicação anterior no histórico.
- A revisão enviada pelo navegador é comparada com a revisão atual no MongoDB; diferença retorna HTTP 409.
- O documento de fluxo continua no schema `2.0.0` com `flow`, `settings`, `viewport`, `lanes`, `nodes` e `edges`.
- O backend normaliza documentos importados e não cria condições de negócio artificiais.
- Participantes do projeto são propagados aos fluxos vinculados.
