# Diagnóstico e plano — spacedev_test — 28/10/2026

## Escopo e evidência

Consulta local em 127.0.0.1:3306, servidor Alex, MySQL 8.4.3. Nenhuma migration, seeder, correção de histórico, DDL ou DML foi executada. O auditor usa SELECT/SHOW em transação READ ONLY e termina com rollback; grava somente arquivos locais de relatório. Não houve acesso à produção.

Evidência detalhada: `docs/local-migration-history-audit.json`. Ferramenta reproduzível: `php83 tools/audit-local-migration-history.php`.

| Item | Quantidade |
| --- | ---: |
| Tabelas físicas, incluindo migrations | 46 |
| Tabelas de domínio | 45 |
| Colunas | 373 |
| Índices distintos por tabela/nome | 159 |
| Chaves estrangeiras | 83 |
| Triggers | 6 |
| Arquivos de migrations ativos | 95 |
| Registros efetivos em migrations | 2 |
| Migrations pendentes | 93 |
| Usuários / projetos / documentos / tarefas | 5 / 2 / 3 / 3 |

As contagens exatas de todas as tabelas estão no JSON, sem exportação de seus registros. As 83 FKs foram verificadas também por consultas anti-join, incluindo chaves compostas: zero órfãos encontrados.

## Causa

O domínio já existia antes da cadeia de criação atual. O histórico contém somente `2026_10_18_000003_add_project_documentation_workflow` (id 1, batch 1) e `2026_10_19_000001_add_task_deadlines` (id 2, batch 2). A documentação de auditoria anterior registra que 42 tabelas de domínio não tinham migration de criação e os seis triggers não tinham migration.

Depois foram introduzidas 89 migrations individuais: 43 de tabelas, 40 de FKs e seis de triggers. Elas descrevem o esquema existente, mas não foram adotadas no histórico deste banco. Laravel decide pelo nome em migrations, sem deduzir execução a partir das tabelas. Assim, tenta criar ai_provider_configs novamente e para antes de chegar à migration de organizações.

Não há evidência de que seja necessário apagar dados ou recriar tabelas. Não é possível determinar somente pelo esquema se o domínio original foi criado manualmente ou importado de dump; a ausência histórica de migrations está documentada, mas o mecanismo inicial de provisionamento não foi comprovado.

## Correspondência estrutural e diferenças

Todos os 46 SHOW CREATE TABLE atuais correspondem ao inventário anterior versionado, após normalização explícita de contador AUTO_INCREMENT, ordem das definições, largura de inteiros e representações equivalentes documentadas. Foram comparados tipos, sinal, nulabilidade, defaults, collations, comentários, índices/ordem de colunas, FKs compostas e ações referenciais. Os seis triggers correspondem em nome, evento, tabela, timing e corpo. O JSON guarda também definers e SQL modes para revisão de portabilidade.

Os 94 arquivos anteriores à migration de organizações têm SHA-256 idêntico ao manifest individual revisado. A classificação das 89 migrations base combina essa integridade com o inventário estrutural e os resultados de instalação isolada já existentes no repositório. Não foi feito novo ensaio de execução nem criado banco nesta auditoria. A base tem ROW_FORMAT=DYNAMIC explícito; as tabelas locais já usam Dynamic. Objetos posteriores de workflow/prazos e FKs não são considerados divergências da migration de criação original.

| Migrations | Histórico | Resultado / ação proposta |
| --- | --- | --- |
| `0000_01_01_000001` até `0000_01_01_000043` | 43 pendentes | Tabelas, colunas e índices base representados; candidatas à adoção no histórico após backup e ensaio autorizado. |
| `0000_01_02_000044` até `0000_01_02_000083` | 40 pendentes | As 79 FKs base representadas; quatro FKs adicionais pertencem aos drafts do workflow. Candidatas à adoção. |
| `0000_01_03_000084` até `0000_01_03_000089` | 6 pendentes | Seis triggers representados; candidatas à adoção. |
| `0001_01_01_000000_create_users_table` | Pendente | Apesar do nome, cria apenas password_reset_tokens e sessions nesta versão. Ambas ausentes; deve ser executada, não marcada como já aplicada. Não recria users. |
| `0001_01_01_000001_create_cache_table` | Pendente | cache e cache_locks ausentes; execução necessária. |
| `0001_01_01_000002_create_jobs_table` | Pendente | jobs, job_batches e failed_jobs ausentes; execução necessária. |
| `2026_10_18_000003_add_project_documentation_workflow` | Registrada, batch 1 | Drafts, quatro FKs, section_key e índices de seção presentes. Divergência de implementação nos dois índices condicionais, descrita abaixo. Não reexecutar. |
| `2026_10_19_000001_add_task_deadlines` | Registrada, batch 2 | start_date, due_date, completed_at e tasks_due_date_index presentes e correspondentes. Não reexecutar. |
| `2026_10_23_000001_add_organization_access_management` | Pendente | Cinco tabelas e coluna/FK de projeto completamente ausentes. Não houve instalação parcial observável. Deve ser aplicada após ensaio e autorização. É nova e ainda não consta no manifest individual antigo. |

Divergência do workflow: `uq_dcr_pending_document` e `uq_tccr_pending_context` usam expressões funcionais diretamente no MySQL 8 local. O código atual cria `pending_document_id` e `pending_technical_context_id` como colunas virtuais e indexa essas colunas. As duas colunas estão ausentes no banco local. A intenção de unicidade é a mesma, mas a estrutura não é idêntica. Preservar o registro histórico existente; resolver com uma nova migration aditiva e revisada, nunca reaplicando o workflow completo.

## Dependências e conflitos

As tabelas base precedem as FKs, o que resolve referências circulares entre entidades e versões. Os triggers vêm depois das tabelas/FKs. O workflow depende de users, documents, technical_contexts, versões, solicitações e decisões. Prazos depende de tasks. Tudo isso existe neste banco.

Executar as 89 migrations base causaria colisões de tabelas, constraints e triggers. Reexecutar o workflow colidiria com drafts/section_key/índices; reexecutar prazos colidiria com suas três colunas. Não apagar registros atuais nem renomear migrations. Não executar rollback genérico: vários down bloqueiam explicitamente a operação e o workflow tem down destrutivo.

Organizações depende de users.id e projects.id, ambos bigint unsigned em InnoDB. Não há colisão dos cinco nomes novos ou de projects.organization_id. A migration adiciona nove FKs e não contém INSERT/UPDATE de usuários, papéis ou participações existentes. As regras de acesso no código mantêm o caminho legado para projetos sem organização; não atribuir organizações automaticamente, pois isso mudaria o contexto de autorização.

## Plano proposto — NÃO executado; depende de autorização

1. Suspender gravações e workers durante backup e intervenção. Capturar novamente identidade do banco, DDL, triggers, histórico e contagens; interromper se houver mudança desde esta auditoria. Preservar uma cópia privada dos arquivos de configuração necessários à recuperação, sobretudo APP_KEY, sem incluí-los no relatório/Git.
2. Fazer backup completo e verificar restauração em banco separado. O teste de restauração deve conservar o banco original; não reutilizar validadores de instalação vazia sobre spacedev_test.
3. Em cópia isolada, ensaiar adoção das 89 migrations base: allowlist com os nomes completos do anexo, hashes dos arquivos e validação estrutural antes de cada registro. Inserir somente os registros ausentes em migrations dentro de transação; não chamar up dessas migrations. Preservar ids/batches existentes. Usar novo batch de adoção e documentar que não representa execução histórica. Não usar rollback de batch para desfazer a adoção.
4. Aplicar somente as três migrations de infraestrutura, cada caminho explícito e previamente conferido. Elas criam sete tabelas ausentes e não escrevem dados de usuários/permissões. Não executar seeders.
5. Aplicar somente a migration de organizações por caminho explícito. Validar os objetos após cada etapa. Em falha, parar e inventariar o estado parcial, porque DDL MySQL causa commits implícitos e a migration inteira não é atômica. Não repetir cegamente nem chamar down.
6. Preparar separadamente uma nova migration para as duas colunas virtuais/índices condicionais. Verificar duplicidades pending; criar as colunas e índices temporários equivalentes antes de remover apenas os índices funcionais antigos e restabelecer seus nomes. Isso preserva a proteção de unicidade durante a transição. Ensaiar locks e compatibilidade; nenhuma tabela será apagada/recriada. A conversão exige aprovação própria junto do plano final de SQL.
7. Repetir auditoria e comparar registros existentes (hashes consistentes em ambiente privado, além das contagens), FKs/triggers e histórico. Validar login, acesso legado aos dois projetos, documentos e tarefas, e GET /admin/organizations com administrador autorizado. Não criar membros, organizações ou alterar papéis como parte da validação local.
8. Somente após ensaio aprovado, repetir o procedimento no local autorizado. Nenhuma etapa autoriza intervenção em produção.

Meta após adoção de 89 + infraestrutura + organizações: 58 tabelas, 95 registros de migrations, 92 FKs e seis triggers. Sem conversão condicional ainda haverá a divergência histórica do workflow. A nova migration de conversão acrescentaria um registro, chegando a 96. Com organizações recém-criadas, suas cinco tabelas ficam vazias, e projects.organization_id fica NULL nos projetos existentes. Usuários, projetos e demais registros existentes devem conservar seus valores de negócio.

## Aplicação de organizações

Comando proposto, somente após autorização, backup e ensaio:

```powershell
php83 artisan migrate --database=mysql --path=database/migrations/2026_10_23_000001_add_organization_access_management.php
```

Não usar migrate sem --path antes da adoção da base. A execução isolada por --path evita o bloqueio inicial, mas, sozinha, não reconcilia o histórico. Resultado esperado: organizations, organization_memberships, teams, team_memberships e project_team; coluna nullable projects.organization_id, índice e FK RESTRICT. As tabelas existentes continuam intactas. Agendar janela pelo ALTER TABLE projects e possíveis metadata locks. A ausência atual de organizations explica o erro da página; testar a página após aplicar a migration, pois esta inspeção não substitui teste HTTP.

## MySQL 5.7

O esquema local atual não pode ser importado sem adaptação em MySQL 5.7: os dois índices funcionais exigem MySQL >= 8.0.13. A cadeia atual usa colunas virtuais indexadas para esse propósito e visa MySQL 5.7.44. Organizações usa recursos disponíveis em 5.7; slug e nome de equipe de 150 caracteres exigem planejamento de índices utf8mb4.

Exigir MySQL 5.7.44 real, InnoDB Dynamic/Barracuda, large_prefix, limite de índice adequado e página de 16KB conforme preflight existente; revisar TRIGGER, definers, SQL modes, charset/collation e binary log com o provedor. Os artefatos de testes existentes aprovam MySQL 8.4.3, não 5.7. Não declarar compatibilidade final até instalação e validação funcional em instância 5.7.44 isolada. Não transportar histórico reconciliado para um banco vazio sem executar sua cadeia.

Fontes oficiais: [índices funcionais MySQL 8](https://dev.mysql.com/doc/refman/8.0/en/create-index.html), [índices sobre colunas geradas MySQL 5.7](https://docs.oracle.com/cd/E17952_01/mysql-5.7-en/generated-column-index-optimizations.html), [manual MySQL 5.7](https://downloads.mysql.com/docs/refman-5.7-en.pdf).

## Backup e validação — comandos propostos

Executar mysqldump do MySQL instalado, usando caminho completo se não estiver no PATH. Substituir USUARIO_LOCAL pelo usuário autorizado. -p solicita senha sem expô-la no shell. Não executar estes comandos contra produção. O diretório de backup deve ser privado, fora do webroot e do Git.

```powershell
$backupDir = Join-Path $env:LOCALAPPDATA 'SPACEdev-backups'
New-Item -ItemType Directory -Force -Path $backupDir
$backupFile = Join-Path $backupDir ('spacedev_test-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.sql')
mysqldump --host=127.0.0.1 --port=3306 --user=USUARIO_LOCAL -p --single-transaction --quick --routines --events --triggers --hex-blob --no-tablespaces --set-gtid-purged=OFF --databases spacedev_test --result-file="$backupFile"
if ($LASTEXITCODE -ne 0) { throw 'Backup falhou; interromper.' }
Get-Item -LiteralPath $backupFile
Get-FileHash -Algorithm SHA256 -LiteralPath $backupFile
php83 artisan migrate:status --database=mysql
php83 tools/audit-local-migration-history.php
```

O dump com --databases contém USE spacedev_test: não importá-lo cegamente no servidor original para testar restauração. Restaurar em outra instância isolada ou preparar backup sem --databases especificamente para restauração em outro nome. Validar restauração, tamanho, objetos, contagens e hashes antes de qualquer intervenção. As contagens isoladamente não provam preservação integral.

Consultas úteis em cliente SQL na conexão local:

```sql
SELECT DATABASE(), VERSION(), @@hostname, @@port;
SELECT id, migration, batch FROM migrations ORDER BY id;
SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE();
SHOW CREATE TABLE users;
SHOW CREATE TABLE projects;
SELECT document_id, COUNT(*) FROM document_change_requests WHERE status='pending' GROUP BY document_id HAVING COUNT(*)>1;
SELECT technical_context_id, COUNT(*) FROM technical_context_change_requests WHERE status='pending' GROUP BY technical_context_id HAVING COUNT(*)>1;
```

O auditor substitui seu JSON em nova execução. Arquivar a evidência anterior antes da intervenção. `tools/schema-audit.php --verify` compara com o inventário antigo e deve passar agora, mas falhará legitimamente depois de criar novas tabelas; não usá-lo como critério de sucesso pós-correção sem revisar a expectativa.

## Anexo: classificação por arquivo

| Migration | Diagnóstico |
| --- | --- |
| `0000_01_01_000001_create_ai_provider_configs_table` | Representada; adotar após autorização |
| `0000_01_01_000002_create_assistant_actions_table` | Representada; adotar após autorização |
| `0000_01_01_000003_create_assistant_conversations_table` | Representada; adotar após autorização |
| `0000_01_01_000004_create_assistant_messages_table` | Representada; adotar após autorização |
| `0000_01_01_000005_create_audit_logs_table` | Representada; adotar após autorização |
| `0000_01_01_000006_create_categories_table` | Representada; adotar após autorização |
| `0000_01_01_000007_create_document_change_request_decisions_table` | Representada; adotar após autorização |
| `0000_01_01_000008_create_document_change_requests_table` | Representada; adotar após autorização |
| `0000_01_01_000009_create_document_tags_table` | Representada; adotar após autorização |
| `0000_01_01_000010_create_document_versions_table` | Representada; adotar após autorização |
| `0000_01_01_000011_create_documents_table` | Representada; adotar após autorização |
| `0000_01_01_000012_create_github_connections_table` | Representada; adotar após autorização |
| `0000_01_01_000013_create_github_query_logs_table` | Representada; adotar após autorização |
| `0000_01_01_000014_create_github_webhook_events_table` | Representada; adotar após autorização |
| `0000_01_01_000015_create_problem_diagnoses_table` | Representada; adotar após autorização |
| `0000_01_01_000016_create_problem_event_histories_table` | Representada; adotar após autorização |
| `0000_01_01_000017_create_problems_table` | Representada; adotar após autorização |
| `0000_01_01_000018_create_project_environments_table` | Representada; adotar após autorização |
| `0000_01_01_000019_create_project_memberships_table` | Representada; adotar após autorização |
| `0000_01_01_000020_create_project_technologies_table` | Representada; adotar após autorização |
| `0000_01_01_000021_create_projects_table` | Representada; adotar após autorização |
| `0000_01_01_000022_create_prompt_change_request_decisions_table` | Representada; adotar após autorização |
| `0000_01_01_000023_create_prompt_change_requests_table` | Representada; adotar após autorização |
| `0000_01_01_000024_create_prompt_favorites_table` | Representada; adotar após autorização |
| `0000_01_01_000025_create_prompt_templates_table` | Representada; adotar após autorização |
| `0000_01_01_000026_create_prompt_variables_table` | Representada; adotar após autorização |
| `0000_01_01_000027_create_prompt_versions_table` | Representada; adotar após autorização |
| `0000_01_01_000028_create_roles_table` | Representada; adotar após autorização |
| `0000_01_01_000029_create_solution_change_request_decisions_table` | Representada; adotar após autorização |
| `0000_01_01_000030_create_solution_change_requests_table` | Representada; adotar após autorização |
| `0000_01_01_000031_create_solution_validations_table` | Representada; adotar após autorização |
| `0000_01_01_000032_create_solution_versions_table` | Representada; adotar após autorização |
| `0000_01_01_000033_create_solutions_table` | Representada; adotar após autorização |
| `0000_01_01_000034_create_tags_table` | Representada; adotar após autorização |
| `0000_01_01_000035_create_task_comments_table` | Representada; adotar após autorização |
| `0000_01_01_000036_create_task_relations_table` | Representada; adotar após autorização |
| `0000_01_01_000037_create_task_status_histories_table` | Representada; adotar após autorização |
| `0000_01_01_000038_create_tasks_table` | Representada; adotar após autorização |
| `0000_01_01_000039_create_technical_context_change_request_decisions_table` | Representada; adotar após autorização |
| `0000_01_01_000040_create_technical_context_change_requests_table` | Representada; adotar após autorização |
| `0000_01_01_000041_create_technical_context_versions_table` | Representada; adotar após autorização |
| `0000_01_01_000042_create_technical_contexts_table` | Representada; adotar após autorização |
| `0000_01_01_000043_create_users_table` | Representada; adotar após autorização |
| `0000_01_02_000044_add_assistant_actions_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000045_add_assistant_conversations_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000046_add_assistant_messages_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000047_add_audit_logs_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000048_add_categories_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000049_add_document_change_request_decisions_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000050_add_document_change_requests_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000051_add_document_tags_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000052_add_document_versions_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000053_add_documents_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000054_add_github_connections_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000055_add_github_query_logs_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000056_add_problem_diagnoses_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000057_add_problem_event_histories_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000058_add_problems_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000059_add_project_environments_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000060_add_project_memberships_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000061_add_project_technologies_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000062_add_projects_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000063_add_prompt_change_request_decisions_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000064_add_prompt_change_requests_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000065_add_prompt_favorites_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000066_add_prompt_templates_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000067_add_prompt_variables_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000068_add_prompt_versions_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000069_add_solution_change_request_decisions_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000070_add_solution_change_requests_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000071_add_solution_validations_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000072_add_solution_versions_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000073_add_solutions_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000074_add_tags_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000075_add_task_comments_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000076_add_task_relations_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000077_add_task_status_histories_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000078_add_tasks_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000079_add_technical_context_change_request_decisions_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000080_add_technical_context_change_requests_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000081_add_technical_context_versions_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000082_add_technical_contexts_foreign_keys` | Representada; adotar após autorização |
| `0000_01_02_000083_add_users_foreign_keys` | Representada; adotar após autorização |
| `0000_01_03_000084_create_trg_document_tags_scope_bi` | Representada; adotar após autorização |
| `0000_01_03_000085_create_trg_document_tags_scope_bu` | Representada; adotar após autorização |
| `0000_01_03_000086_create_trg_task_relations_no_self_bi` | Representada; adotar após autorização |
| `0000_01_03_000087_create_trg_task_relations_no_self_bu` | Representada; adotar após autorização |
| `0000_01_03_000088_create_trg_documents_category_scope_bi` | Representada; adotar após autorização |
| `0000_01_03_000089_create_trg_documents_category_scope_bu` | Representada; adotar após autorização |
| `0001_01_01_000000_create_users_table` | Ausente; executar após autorização |
| `0001_01_01_000001_create_cache_table` | Ausente; executar após autorização |
| `0001_01_01_000002_create_jobs_table` | Ausente; executar após autorização |
| `2026_10_18_000003_add_project_documentation_workflow` | Registrada; índices condicionais divergentes |
| `2026_10_19_000001_add_task_deadlines` | Registrada; correspondente |
| `2026_10_23_000001_add_organization_access_management` | Ausente; executar após autorização |
