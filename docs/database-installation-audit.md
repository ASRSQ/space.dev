> Atualização: este documento descreve a cadeia consolidada anterior. Para este release, siga [migrations individuais](individual-migrations.md). MySQL 5.7.44 continua pendente de validação. Não aplique o pacote antigo sobre a nova cadeia.

# Auditoria do banco e instalação limpa — 20/10/2026

## Resultado

Antes do ajuste, as migrations não permitiam instalar o SPACE.dev do zero. O banco `spacedev_test` (MySQL 8.4.3) tinha **46 tabelas, 373 colunas, 159 índices distintos, 83 FKs e 6 triggers**. Nenhuma CHECK constraint explícita e nenhuma view foram encontradas. A tabela `migrations` tinha somente os registros da Central de Documentação e dos prazos.

O [inventário completo](schema-inventory.json) registra DDL por tabela, colunas/tipos/defaults/nullability/expressões geradas, índices, constraints, FKs e ações referenciais, triggers e histórico de migrations. Ele não contém registros das tabelas da aplicação. O esquema foi consultado com SELECT/SHOW; não foram executadas migrations ou alterações em `spacedev_test`. A verificação final `php tools/schema-audit.php --verify` confirmou DDL, triggers e histórico de migrations inalterados.

## Migrations encontradas e diferenças

| Migration original | Diagnóstico | Tratamento |
| --- | --- | --- |
| `0001_01_01_000000_create_users_table` | Estrutura padrão Laravel incompatível com User: `password` em vez de `password_hash`; faltavam `role_id`, `status`, FK de papel; comprimentos, índices e timestamps divergentes. `email_verified_at` não existe no banco real. | Usuários e papéis passam a pertencer à base anterior. Arquivo original preserva criação de password_reset_tokens e sessions, sem criar ou remover users em duplicidade. |
| `0001_01_01_000001_create_cache_table` | cache e cache_locks ausentes no banco local, embora necessárias com CACHE_STORE=database. | Mantida. |
| `0001_01_01_000002_create_jobs_table` | jobs, job_batches e failed_jobs ausentes no banco local. | Mantida. |
| `2026_10_18_000003_add_project_documentation_workflow` | Pressupõe documents, technical_contexts, solicitações e usuários existentes. Cria somente drafts, section_key e índices únicos de revisão pendente. | Mantida sem mudanças; objetos exclusivos removidos da base anterior para evitar duplicação. |
| `2026_10_19_000001_add_task_deadlines` | Pressupõe tasks; acrescenta start_date, due_date, completed_at e índice de due_date. | Mantida sem mudanças; esses objetos não entram na base anterior. |

Das 45 tabelas de domínio, **42 não tinham migration de criação**, users tinha uma criação incompatível e apenas as duas tabelas de drafts tinham criação compatível. Todos os 6 triggers estavam sem migration. Os testes existentes frequentemente constroem esquemas SQLite próprios; por isso, passar nessa suíte isoladamente não comprovava instalação limpa.

O DatabaseSeeder ainda criava `test@example.com` pela factory. Agora cria somente os cinco papéis suportados. `space:create-admin` usa os casts/regras de autenticação existentes, valida senha e e-mail e cria o primeiro super-admin interativamente; não há senha fixa nem usuário importado.

## Base preparada e ordem

`0000_01_01_000000_create_space_domain.php` executa o DDL revisado de `database/schema/space-baseline.json`: 43 tabelas, 79 FKs e 6 triggers. Primeiro cria todas as tabelas e seus índices; depois acrescenta FKs, resolvendo ciclos de ponteiros de versão corrente para tabelas de versões e vice-versa; por fim cria triggers. As verificações referenciais permanecem habilitadas durante toda a instalação. As quatro FKs de drafts entram pela migration incremental posterior, totalizando as 83 do domínio original.

Foram preservados nomes de índices/constraints, índices compostos, unicidade condicional, enums, colunas geradas, timestamps/defaults, ON UPDATE e ON DELETE. Os triggers de INSERT/UPDATE preservam as regras de categorias e tags pertencentes ao projeto do documento e proibição de tarefa relacionada consigo mesma. Não foram adicionadas regras de negócio novas nem alterados modelos ou serviços.

A base recusa um domínio já existente ou parcialmente instalado e recusa rollback destrutivo. Ela é destinada à instalação vazia, **não à atualização automática do banco local existente**. Seu DDL é versionado e não depende do inventário em docs no momento da instalação. O script de preparação é offline, sem acesso MySQL. Não se deve regenerar uma migration já publicada; evoluções posteriores exigem novas migrations.

## Validação executada

O [resultado reproduzível](clean-install-validation.json) identifica o banco isolado gerado com nome aleatório. Todas as seis migrations executaram em MySQL 8.4.3. O resultado contém **53 tabelas**: 45 do domínio, 7 de infraestrutura Laravel e migrations. As 45 tabelas do domínio e os 6 triggers correspondem ao inventário, sem divergências de DDL após normalizar ordem de índices/FKs, contadores AUTO_INCREMENT e a representação equivalente de CHARACTER SET utf8mb4. Os tipos, collations, nulabilidade, defaults e restrições são comparados.

Depois de migrate, havia **zero registros de aplicação**. Foram executadas 19 verificações em transação, envolvendo:

- Seeder de produção sem usuário de teste; hash da senha; acesso de super-admin e recusa de viewer.
- Projeto, proprietário e associação de membro.
- Rascunho, submissão, revisão, publicação e versão corrente de documento e contexto técnico; restrição a uma revisão pendente.
- Criação de tarefas por TaskCreationService, datas, conclusão por TaskLifecycleService e histórico.
- Comentários, relação entre tarefas e recusa de autorrelação/orfandade.
- Rejeição de categoria/tag de outro projeto pelos triggers.
- Conexão GitHub com token cifrado, logs e persistência de webhook.
- Configuração IA cifrada, conversa, mensagem, ação e auditoria.

Os registros desses testes foram desfeitos. Não houve chamadas a GitHub ou provedores IA. São testes de persistência/fluxos locais, não de conectividade externa ou navegação completa em hospedagem.

A suíte PHPUnit terminou com **131 testes, 4.935 assertions e 8 skips**, sem falhas. Os skips são os testes MySQL antigos, que exigem uma instância de documentação com porta/datadir fixos; a instalação completa foi coberta separadamente pelo novo validador MySQL. Após o ajuste de concorrência do comando administrativo, seus 2 testes/15 assertions foram repetidos com sucesso.

## Hospedagem e limites

O [guia de implantação](shared-hosting-installation.md) descreve requisitos, dependências, .env, diretório público, migrations, administrador, filas/cron e recuperação. A execução foi validada localmente, não na hospedagem contratada. MySQL 8.4.3 foi a versão efetivamente testada; MariaDB não é compatível com os índices funcionais atuais. O instalador não requer privilégios globais, mas o plano deve permitir TRIGGER e uma configuração de binary log compatível. Se o servidor exigir SUPER para triggers, o provedor precisa resolver essa configuração; o projeto não executa SET GLOBAL.

A conta local configurada criou os bancos isolados para o ensaio; esse preparo local exige CREATE DATABASE. Não foi realizado ensaio com uma conta nova de privilégios restritos, nem alteração de privilégios do servidor. A hospedagem deve fornecer previamente o banco e permissões somente nele. Os bancos temporários foram preservados para inspeção, sem remoção automática. Não use o roteiro de instalação vazia no banco existente.

## Inventário por tabela

Contagens do banco local original; índices compostos contam como um índice.

| Tabela | Colunas | Índices | FKs |
| --- | ---: | ---: | ---: |
| ai_provider_configs | 9 | 2 | 0 |
| assistant_actions | 11 | 5 | 2 |
| assistant_conversations | 6 | 3 | 2 |
| assistant_messages | 6 | 2 | 1 |
| audit_logs | 11 | 5 | 2 |
| categories | 6 | 3 | 1 |
| document_change_request_decisions | 6 | 3 | 2 |
| document_change_requests | 10 | 4 | 2 |
| document_drafts | 6 | 3 | 2 |
| document_tags | 3 | 2 | 2 |
| document_versions | 8 | 4 | 2 |
| documents | 11 | 7 | 3 |
| github_connections | 11 | 2 | 1 |
| github_query_logs | 8 | 3 | 1 |
| github_webhook_events | 9 | 4 | 0 |
| migrations | 3 | 1 | 0 |
| problem_diagnoses | 7 | 3 | 2 |
| problem_event_histories | 6 | 3 | 2 |
| problems | 11 | 3 | 2 |
| project_environments | 7 | 2 | 1 |
| project_memberships | 10 | 6 | 4 |
| project_technologies | 6 | 2 | 1 |
| projects | 11 | 4 | 1 |
| prompt_change_request_decisions | 6 | 3 | 2 |
| prompt_change_requests | 11 | 3 | 2 |
| prompt_favorites | 4 | 3 | 2 |
| prompt_templates | 12 | 6 | 4 |
| prompt_variables | 8 | 3 | 2 |
| prompt_versions | 9 | 4 | 2 |
| roles | 5 | 3 | 0 |
| solution_change_request_decisions | 6 | 3 | 2 |
| solution_change_requests | 11 | 3 | 2 |
| solution_validations | 7 | 4 | 3 |
| solution_versions | 9 | 4 | 2 |
| solutions | 10 | 5 | 3 |
| tags | 5 | 3 | 1 |
| task_comments | 6 | 3 | 2 |
| task_relations | 5 | 3 | 2 |
| task_status_histories | 7 | 3 | 2 |
| tasks | 16 | 5 | 3 |
| technical_context_change_request_decisions | 6 | 3 | 2 |
| technical_context_change_requests | 11 | 4 | 2 |
| technical_context_drafts | 8 | 3 | 2 |
| technical_context_versions | 9 | 4 | 2 |
| technical_contexts | 11 | 5 | 2 |
| users | 9 | 3 | 1 |
