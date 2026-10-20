# Gerenciamento de prazos das tarefas

## Diagnóstico de 19/10/2026

A inspeção do MySQL `spacedev_test` foi somente de leitura. A tabela `tasks` contém `id`, `project_id`, `title`, `description`, `task_type`, `priority`, `status`, `assigned_user_id`, `reporter_user_id`, `is_archived`, `archived_at`, `created_at` e `updated_at`. Não existem datas equivalentes a início, entrega ou conclusão. `archived_at` representa arquivamento e não pode ser reutilizado como conclusão.

A tabela `migrations` contém somente `2026_10_18_000003_add_project_documentation_workflow` (batch 1). Portanto, o schema existente não pode ser reconstruído a partir desse histórico, nem se deve executar todas as migrations pendentes no original.

O domínio utiliza modelos Eloquent, sem entidades, repositórios ou interfaces de tarefas separados. `TaskCreationService` controla criação e `TaskLifecycleService` centraliza transições com transação, bloqueio e histórico. Os status operacionais são `todo`, `in_progress`, `review` e `done`; `archived` é arquivamento. Apenas `done` representa conclusão.

`TaskResource` define formulário, detalhes e tabela. `CreateTask` delega ao serviço de criação; `EditTask` realiza atualização autorizada em transação. A entrada principal é `TaskKanban`, incluindo criação rápida e movimentação. A listagem tabular existente agora tem rota `/admin/tasks/list`, acessível pelo quadro.

`TaskPolicy` restringe o recurso a administradores e superadministradores ativos; projetos arquivados são somente para consulta. Atribuições continuam sob `TaskAssignmentService`. Comentários, relações direcionais e histórico não tiveram sua estrutura alterada. Os indicadores usam os atributos da própria tarefa, sem consultas adicionais por registro.

## Regras implementadas

- `start_date` e `due_date` são DATE opcionais; `completed_at` é DATETIME opcional e protegido de atribuição em massa e formulários.
- Entrega sem início é válida. Quando ambos existem, entrega deve ser igual ou posterior ao início. A criação valida os dados recebidos; o modelo também protege o intervalo nas gravações Eloquent.
- A primeira transição efetiva para `done` registra a conclusão se vazia. Repetir `done` preserva a data, inclusive quando desconhecida em registros antigos. Criação rápida diretamente em `done` registra a hora atual.
- Reabrir para um status operacional ou restaurar do arquivo limpa `completed_at`. Concluir novamente registra o novo encerramento. O histórico anterior permanece integral e a gravação da conclusão participa da mesma transação do histórico.
- Arquivar preserva a conclusão já registrada, mas não cria uma conclusão para tarefas abertas. Tarefas arquivadas sem conclusão não são classificadas como atrasadas.
- Tarefas antigas não recebem backfill. Uma tarefa `done` com prazo e sem conclusão mostra “Conclusão sem data conhecida”.
- Sem entrega: “Sem prazo”. Tarefas abertas: “No prazo”, “Vence hoje” ou “Atrasada”. Conclusão até o fim do dia da entrega: “Concluída no prazo”; após esse dia: “Concluída com atraso”.
- Datas são comparadas no fuso `config('app.timezone')`, atualmente UTC. O cálculo usa dias de calendário; não há persistência de dias restantes ou atraso.
- “Prazo próximo” inclui hoje e os próximos sete dias, apenas para tarefas abertas e não arquivadas. Os filtros combinam com os filtros existentes tanto na tabela quanto no quadro.

## Arquivos

Criados:

- `database/migrations/2026_10_19_000001_add_task_deadlines.php`
- `docs/task-deadlines.md`

Modificados:

- `app/Models/Task.php`
- `app/Services/TaskCreationService.php`
- `app/Services/TaskLifecycleService.php`
- `app/Filament/Resources/Tasks/TaskResource.php`
- `app/Filament/Resources/Tasks/Pages/EditTask.php`
- `app/Filament/Resources/Tasks/Pages/TaskKanban.php`
- `resources/views/filament/tasks/kanban-toolbar.blade.php`
- `tests/Feature/Filament/TaskResourceTest.php`

## Validação isolada

PHP 8.3.16 do Laragon, PHPUnit e SQLite `:memory:`. O PHP padrão do PATH é 8.2.12, por isso foi usado o executável explícito do Laragon. Nos testes finais o host MySQL foi substituído por `127.0.0.1:1` e os testes opcionais MySQL ficaram desativados. Nenhuma migration, seeder ou teste foi executado contra o MySQL original.

Cobertura adicional: criação com e sem entrega, criação concluída, edição, intervalo inválido no formulário e serviço, proteção da conclusão, indicadores e dias restantes, limite do dia no fuso da aplicação, conclusão pontual e tardia, repetição de status, arquivamento, restauração, reabertura, histórico, registros antigos, filtros combinados, ordenação tabular e filtros do quadro. A migration é aplicada na preparação dos testes de recurso; um teste adicional reverte e reaplica sobre uma tarefa preexistente para verificar preservação dos dados. Os testes existentes continuam cobrindo permissões e rollback do histórico.

Pint aplicado aos arquivos PHP alterados. Não houve validação visual manual no navegador nem execução desta migration em MySQL isolado; a validação automatizada de schema foi feita em SQLite.

Resultado final da suíte completa: **129 testes, 4.920 asserções, 8 ignorados, nenhuma falha**. Os ignorados são testes opcionais de MySQL. Evidências locais em `storage/logs/deadline-suite.txt` e `storage/logs/deadline-suite.xml`.

## Integração e ativação segura — pendente de autorização

O código está preparado no workspace; a ativação no banco original depende da migration. Não utilizar os novos formulários para gravar no original antes dessa etapa, pois as colunas ainda não existem. Não houve integração de IA.

1. Revisar este relatório e autorizar explicitamente a alteração estrutural em `spacedev_test`.
2. Fazer backup verificável do banco, com possibilidade de restauração. Repetir o preflight de leitura de colunas, índices e histórico para detectar mudanças posteriores ao diagnóstico.
3. Em uma cópia MySQL 8.4 isolada, validar a migration específica e conferir contagens, registros existentes, histórico e novas colunas nulas. Não executar seeders ou migrations gerais.
4. Programar janela sem gravações para ativar código e schema em conjunto. Conferir conexão e nome do banco antes de qualquer comando.
5. Após autorização, inspecionar o SQL de apenas esta migration com `--pretend`; em seguida executar exclusivamente o mesmo caminho, sem `--pretend`:

```powershell
& C:/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan migrate --path=database/migrations/2026_10_19_000001_add_task_deadlines.php --pretend
# Apenas após revisão do SQL e autorização explícita:
& C:/laragon/bin/php/php-8.3.16-Win32-vs16-x64/php.exe artisan migrate --path=database/migrations/2026_10_19_000001_add_task_deadlines.php
```

Essa migration adiciona somente as três colunas nulas e um índice em `due_date`; não atualiza registros existentes. Não editar migrations antigas. Não executar `migrate` sem `--path`, `migrate:fresh`, `migrate:refresh` ou `db:wipe`.

6. Conferir o novo registro em `migrations`, as três colunas, índice e contagens. Validar criação, edição, transição e filtros com um usuário autorizado.
7. Em caso de falha de aplicação, preferir reverter o código mantendo as colunas aditivas. O `down()` remove as novas datas e não deve ser aplicado após uso sem backup e autorização específica para perda desses dados. MySQL não garante rollback transacional de DDL: em falha parcial, inspecionar o schema antes de tentar novamente.
