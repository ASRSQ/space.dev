# Editor de tarefas

> Atualização de 23/10/2026: a revisão posterior acrescentou a página de erros, componentes compartilhados, proteção dos demais campos contra conflitos e testes MySQL. Consulte o [relatório de pré-publicação](editor-prepublication-review.md) para os resultados atuais e comandos HostGator. Os resultados e limitações abaixo registram a entrega inicial.

## Diagnóstico e solução

- A descrição era um `Textarea`, e o `TextEntry` de detalhes exibia texto sem interpretar Markdown. O formulário completo e a criação rápida agora usam `MarkdownEditor` nativo, com anexos desabilitados. A prévia (atualizada ao sair do editor) e os detalhes compartilham `TaskMarkdown`, baseado no CommonMark já instalado. Títulos, listas, links, tabelas, quebras de linha e blocos com classe da linguagem são renderizados com a tipografia `fi-prose`. HTML do usuário é removido e URLs perigosas são bloqueadas. Não há destaque sintático adicional.
- O suporte padrão de checklist do CommonMark gera inputs desabilitados. Um renderer específico gera eventos Livewire apenas quando a Policy permite atualizar a tarefa. A ação reautoriza no servidor, bloqueia a linha no banco e confere o hash da descrição vista pelo usuário. Altera somente o caractere do marcador validado pela árvore Markdown, preservando o restante do texto, incluindo CRLF. Blocos de código não podem ser alterados como checklist. Há indicação de salvamento; conflitos notificam e carregam a descrição atual. O formulário de edição também rejeita uma descrição desatualizada.
- As opções de responsáveis incluíam somente usuários ativos com vínculo ativo no projeto. Um responsável existente fora desse filtro desaparecia das opções e sua atribuição era rejeitada ao salvar. Agora ele permanece visível com indicação de inelegibilidade e pode ser mantido. Uma nova atribuição continua exigindo elegibilidade.

Nenhuma dependência ou migration adicionada. O formato armazenado continua Markdown, sem conversão de dados existentes. As Policies permanecem as mesmas.

## Arquivos

- `app/Support/TaskMarkdown.php`
- `app/Services/TaskAssignmentService.php`
- `app/Filament/Resources/Tasks/TaskResource.php`
- `app/Filament/Resources/Tasks/Pages/EditTask.php`
- `app/Filament/Resources/Tasks/Pages/ViewTask.php`
- `app/Filament/Resources/Tasks/Pages/TaskKanban.php`
- `resources/views/filament/tasks/description.blade.php`
- `resources/views/filament/tasks/description-preview.blade.php`
- `tests/Feature/Filament/TaskResourceTest.php`
- `docs/task-editor.md`

## Testes

Executados com PHP 8.3.16 do Laragon, via `vendor/phpunit/phpunit/phpunit`:

- `tests/Feature/Filament/TaskResourceTest.php`: 40 testes, 346 assertions, todos aprovados.
- `tests/Feature/TaskProjectDomainRelationshipsTest.php`: 4 testes, 33 assertions, todos aprovados.

Os testes usam SQLite em memória e schemas isolados, sem operações no banco da aplicação. Não foi executada validação em MySQL nem interação real no navegador. O PHP padrão do terminal é 8.2.12 e não atende às dependências instaladas, que exigem 8.3 ou superior. Não houve falha preexistente nas suítes relacionadas concluídas.

## Validação manual

1. Como administrador, abra uma tarefa antiga em `/admin/tasks`, confira o responsável, altere somente o título e salve. Confira que responsável e descrição permanecem iguais.
2. Crie ou edite uma descrição com títulos, negrito, listas, tabela, código cercado por crases e itens `- [ ]` / `- [x]`. Saia do editor e confira a prévia; salve e compare com os detalhes. Repita na criação rápida do Kanban.
3. Nos detalhes, marque e desmarque um item. Confira o feedback de salvamento, atualize a página e reabra a tarefa para confirmar persistência.
4. Abra a tarefa em duas abas. Altere a descrição em uma e tente marcar um item na outra: deve aparecer um aviso e a versão atual. Repita mantendo o formulário de edição aberto: o salvamento deve ser bloqueado por conflito.
5. Confira que um usuário sem permissão não consegue editar. Arquive o projeto após abrir os detalhes em outra aba: uma tentativa posterior de alterar o checklist deve ser rejeitada pelo servidor.
6. Em dados locais de teste, revogue o vínculo do responsável atual. Confira a indicação no Select e salve sem mudar o responsável. Depois escolha outro membro ativo e confirme a nova atribuição nos detalhes.
7. Inclua `<script>`, HTML com eventos e link `javascript:` na descrição; confira que nenhum código é executado.
