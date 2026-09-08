# Hotfix 4.1.1 — importação de projetos

Corrige a importação de pacotes `project.zip` gerados pelo Produto Tools anterior e mantém compatibilidade com os pacotes do Fluxos Luiz 4.x.

## Compatibilidade adicionada

- `flows[].flowId` (Produto Tools 3.x) e `flows[].id` (Fluxos Luiz 4.x);
- campo `flows[].file` do manifesto legado;
- `defaultFlowId` e `default_flow_id`;
- `project.json` na raiz ou dentro de uma pasta compactada;
- BOM UTF-8 em `project.json` e nos arquivos de fluxo;
- fallback por varredura da pasta `flows/` quando o manifesto não lista os arquivos;
- preservação de `role`, `group` e `order` declarados no manifesto;
- prevenção de colisão de IDs quando a opção de preservar IDs está habilitada;
- erro de pacote inválido volta para a tela de projetos como mensagem de validação, sem página 500.

## Regressão

`tests/Unit/ProjectBundleCompatibilityTest.php` cobre pacote legado, pacote atual e ZIP com pasta raiz/BOM.
