# Paridade funcional Produto Tools 3.2.6.1 -> Fluxos Luiz 4.1.1

Esta revisão completa a migração funcional para Laravel 12 + MongoDB, preservando os contratos de documento, governança, projetos e editor do Produto Tools.

## Editor visual

- 10 tipos de cards: início, fim, atividade, decisão, subprocesso, evento, espera, documento, integração/API e observação.
- Raias horizontais editáveis, recolhíveis e com autoajuste de altura.
- Drag-and-drop da paleta para o canvas.
- Seleção simples, Ctrl/Shift + clique, Ctrl+A e seleção por área com Shift + arrastar.
- Movimento de grupo, duplicação, exclusão, alinhamento horizontal/vertical e distribuição.
- Undo/redo, zoom, enquadramento, fullscreen e pan por espaço, botão central ou botão direito.
- Minimapa, grade, snap-to-grid e persistência das preferências de visualização.
- Filtros de visão: completa, executiva, operacional, técnica, exceções e raia selecionada.
- Filtros de conexões: todas, seleção, entre raias ou ocultas.
- Roteamento global: suave, reto, ortogonal, corredores simples e corredores inteligentes.
- Busca por card, ID, descrição, responsável, tag e raia.
- Rotas e destaque de caminhos.
- Play do processo com velocidade configurável e decisões interativas.
- Semântica visual de decisões positivas/negativas/neutras.
- Propriedades avançadas: responsável, SLA, nível, categoria, criticidade, tags, documentação e RACI.
- Vínculos de subprocessos por linkedFlowId, linkedFlowEntryNodeId e linkedFlowExitNodeId.
- Navegação entre fluxos do mesmo projeto.

## Persistência, colaboração e governança

- Rascunho manual por usuário + fluxo + revisão, sem autosave oculto.
- Histórico formal de versões e hash SHA-256 do documento.
- Controle otimista por revision.
- Conflito de edição com quatro alternativas: recarregar servidor, salvar como cópia, sobrescrever com revalidação (proprietário/admin) ou continuar editando.
- Comparação de versões por cards, conexões, raias, metadados e configurações.
- Restauração de versão como nova versão atual.
- Compartilhamento viewer/editor/reviewer/approver.
- Presença colaborativa com TTL.
- Comentários por fluxo, card, conexão ou raia, com resolução.
- Workflow draft -> in_review -> approved -> published, além de archive/reopen.
- Templates internos e templates customizados persistidos no MongoDB.

## Qualidade e relatórios

- Validação estrutural do documento.
- Importação resiliente: decisão sem pelo menos duas saídas é convertida em atividade, sem inventar regra de negócio.
- Score de qualidade por estrutura, documentação, responsabilidade, SLA e subprocessos.
- Problemas acionáveis com card, raia, gravidade, motivo e orientação de correção.
- Matriz RACI.
- Exportações: JSON, SVG, PNG, PDF do diagrama, PDF de documentação completa, HTML, CSV de cards, CSV RACI e ZIP completo.

## Projetos

- Visões executive, operational, subprocess e support.
- Mapa de dependências.
- Busca global por fluxo/card/raia/responsável/tag/ID.
- Menor caminho e execução guiada entre fluxos.
- Análise de impacto.
- Detecção de vínculos quebrados, ciclos e órfãos.
- Qualidade consolidada por projeto.
- Releases com snapshot de versão, revisão e hash de cada fluxo.
- Importação/exportação project.zip.
- Importação simultânea de vários JSONs.
- Participantes e herança de visibilidade/permissões.

## Administração

- Usuários compartilháveis via coleção users.
- Papéis user, head_comercial e admin.
- Criação, edição, ativação/desativação, troca de senha e exclusão.
- Proteção contra desativar/remover o último administrador ativo.
- Auditoria e exportação CSV.
- Tema claro/escuro persistido no perfil.
