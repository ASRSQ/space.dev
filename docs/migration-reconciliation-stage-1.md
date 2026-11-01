# Homologação da reconciliação — etapa 1 — 28/10/2026

**Etapa 1 aprovada nos testes locais. Etapa 2 não executada e aguardando aprovação explícita.**

O banco original `spacedev_test`, MySQL 8.4.3 em loopback:3306, permanece com 46 tabelas e dois registros em migrations. Nenhuma correção, seeder, recriação de usuário, alteração de permissões ou DDL foi executada nele. Nenhum seeder foi executado na cópia MySQL. Nenhum banco preexistente além da origem foi acessado para alterações; HostGator e produção não foram acessados.

## Backup e restauração

Foi criado um dump lógico completo com mysqldump 8.4.3: estrutura, dados, índices, FKs e triggers, incluindo as opções routines/events (a origem não possui routines/events). Exportação com single-transaction, sem locks de tabelas, sem DROP TABLE e sem diretiva USE do banco original. Credenciais foram fornecidas somente no ambiente privado do processo, nunca em argumentos ou no relatório.

Backup durável, fora do webroot e do Git:

Backup final: `C:\Users\alex1\AppData\Local\SPACEdev-backups\spacedev_reconcile_20261028_122217_5bd62f53\spacedev_test.sql`.

O primeiro backup também foi preservado em `C:\Users\alex1\AppData\Local\SPACEdev-backups\spacedev_reconcile_20261028_120853_76f94e2f\spacedev_test.sql`.

- Tamanho: **95.923 bytes**.
- SHA-256 final: `e4cd20a4824184d1915c9b587e6322791a6539b866a38d38fee03641553ea270`.
- SHA-256 do primeiro backup: `ae67865fe40655b2edee23e734e4e99272b1f64949a97051ae3cd9f5710fd420`. Os dumps diferem por metadados de exportação; ambos restauraram os mesmos dados/objetos e foram verificados.
- Cópia de trabalho e logs privados preservados também na pasta homônima em `%TEMP%`.
- Bancos temporários criados exclusivamente para o ensaio: primeira cópia **`spacedev_reconcile_20261028_120853_76f94e2f`**, repetição final **`spacedev_reconcile_20261028_122217_5bd62f53`**. Ambos foram preservados para inspeção; nenhum banco preexistente foi alterado. O JSON principal descreve a repetição final.
- Restauração: **PASS**. Os hashes de todas as linhas/colunas originais, contagens, DDL normalizado e metadados dos seis triggers corresponderam à origem antes da reconciliação.

A primeira comparação bruta detectou que SHOW CREATE após o restore explicita `CHARACTER SET utf8mb4` em colunas antes impressas apenas com `COLLATE utf8mb4_unicode_ci`. O charset/collation efetivos não mudaram. Foi usada a normalização já revisada no repositório, sem ignorar tipos, índices, expressões ou FKs. Não houve perda ou ajuste de dados para aceitar a restauração.

## Reconciliação na cópia

Antes de inserir qualquer registro de adoção, foram verificados o inventário integral restaurado, os hashes dos 89 arquivos base contra o manifest revisado e os objetos de cada migration: 43 tabelas com colunas/índices, 79 FKs em 40 arquivos e seis triggers. Todos passaram. O JSON de evidência contém a classificação e SHA-256 de cada arquivo.

As 89 migrations foram adotadas no **batch 3**, em transação, sem executar seus métodos up e sem recriar tabelas. Os dois registros originais, inclusive ids e batches 1 e 2, foram preservados. As três migrations de infraestrutura foram executadas por caminhos explícitos, seguidas por organizações e pela nova migration de conversão dos índices.

| Item | Original e cópia restaurada | Cópia reconciliada |
| --- | ---: | ---: |
| Tabelas | 46 | 58 |
| Índices | 159 | 187 |
| FKs | 83 | 92 |
| Triggers | 6 | 6 |
| Histórico de migrations | 2 | 96 |
| Registros de negócio originais | 97 | 97, hashes iguais |
| Usuários | 5 | 5 |
| Projetos | 2 | 2 |
| Documentos | 3 | 3 |
| Tarefas | 3 | 3 |

Os **95 arquivos existentes no início** estão todos registrados na cópia. A nova `2026_10_28_000001_normalize_workflow_pending_indexes` leva o total a **96 migrations**, sem pendências. A lista por nome, batch, hash e estado está em `docs/migration-reconciliation-rehearsal.json`, campo `migration_status`; o log privado `migration-status.log` contém a saída do Artisan.

Foram criadas as sete tabelas de infraestrutura: password_reset_tokens, sessions, cache, cache_locks, jobs, job_batches e failed_jobs. Organizações acrescentou organizations, organization_memberships, teams, team_memberships e project_team, além de projects.organization_id e sua FK. As 12 tabelas novas terminaram vazias; os dois projetos existentes terminaram com organization_id NULL. Não foram feitas associações automáticas nem alterações de papéis.

## Conversão dos índices condicionais

Nova migration: `database/migrations/2026_10_28_000001_normalize_workflow_pending_indexes.php`.

- Valida ambas as tabelas antes do primeiro DDL e recusa índices/colunas/expressões desconhecidos, duplicidades pending e instalação parcial identificada.
- Para cada tabela legada, adiciona uma coluna virtual bigint unsigned e índice único temporário em comandos separados; depois remove somente o índice funcional e renomeia o substituto para o nome original. A separação respeita a limitação de ADD VIRTUAL em MySQL 5.7.
- Mantém a proteção de unicidade durante toda a troca. Nenhum UPDATE/DELETE/INSERT de registros de negócio e nenhuma tabela apagada/recriada.
- Em instalações novas que já usam as colunas virtuais, valida a representação e não altera o esquema. Esse caminho também foi testado na cópia.
- Bloqueia rollback destrutivo. Como DDL faz commits implícitos, falha intermediária requer inventário e retomada específica; não repetir cegamente.

O ensaio também identificou que MySQL 8.4.3 recusa forçar INPLACE ao adicionar coluna virtual e ao remover o índice funcional legado, por causa de sua coluna virtual interna. O código final deixa o servidor escolher o algoritmo nessas duas operações; elas podem reconstruir fisicamente a tabela pelo algoritmo COPY do próprio ALTER. Não há DROP/CREATE lógico de tabelas nem mudança de valores de negócio. A adição do índice substituto usa INPLACE. Essa limitação e os possíveis locks devem ser considerados na janela da etapa 2.

Na segunda cópia, a tentativa de forçar INPLACE deixou document_change_requests com coluna virtual e índice temporário válidos, mantendo também o índice funcional original. O estado foi inspecionado e validado antes de concluir exclusivamente a troca desse índice com o ALTER sem algoritmo forçado. Depois, a migration concluiu technical_context_change_requests com a sequência final e foi registrada normalmente. A recuperação parcial não alterou linhas; todos os hashes e testes foram repetidos com sucesso. Esse é um exemplo concreto de recuperação por inventário após DDL parcial, em vez de reaplicação cega.

As expressões finais são CASE WHEN status = 'pending' THEN document_id/technical_context_id ELSE NULL END. Os nomes `uq_dcr_pending_document` e `uq_tccr_pending_context` foram conservados. Não restaram índices funcionais nesses dois pontos; os tipos/defaults/índices/FKs do domínio final corresponderam à expectativa versionada com a extensão de organizações.

## Integridade, autenticação e permissões

- As **92 FKs**, incluindo as 83 existentes e as nove novas, passaram em consultas anti-join, inclusive chaves compostas: **zero órfãos**. A comparação do DDL final também preservou colunas e ações referenciais das FKs antigas.
- Os seis triggers conservaram nome, timing, evento, tabela, corpo, definer e SQL mode. Os testes provocaram e confirmaram erro 1644 para INSERT e UPDATE com categoria/tag de outro projeto e autorrelação de tarefa.
- Os nove novos relacionamentos rejeitaram referências inexistentes; as participações rejeitaram duplicidades. Organizações/equipes referenciadas ficaram protegidas por RESTRICT.
- Os índices condicionais rejeitaram segundo pending e transição inválida, liberaram a chave ao aprovar e acompanharam ON UPDATE CASCADE sobre entidades sintéticas.
- Login por credenciais sintéticas válidas passou; senha inválida e conta inativa foram rejeitadas. Nenhuma senha real precisou ser conhecida, alterada ou exposta.
- GET `/admin/organizations`: **200 para administrador sintético** e **403 para programador sem participação organizacional**, em teste HTTP Laravel sobre a cópia.
- Acesso legado a projeto sem organização passou. Em projeto sintético com organização, o acesso exigiu participação organizacional ativa.
- Programador, editor e leitor foram testados com relações MySQL e Policies/Gates: permissões de tarefas/documentação correspondentes, sem conceder administração de usuários nem aprovação documental indevida.

Testes MySQL específicos: **7 testes, 106 assertions, zero falhas/erros/skips**, registrados no `mysql-tests.xml`. Todas as fixtures eram sintéticas e foram desfeitas por rollback. Após os testes, todas as tabelas mantiveram exatamente seus hashes/contagens anteriores aos testes, inclusive tabelas novas vazias e histórico. Contadores AUTO_INCREMENT da cópia podem avançar por fixtures revertidas; isso não altera registros de negócio e não foi realizado no original.

Suíte geral final, em SQLite em memória: **191 testes, 5.228 assertions, zero falhas/erros e 28 skips**. O teste de seeder foi excluído com `--exclude-filter test_role_seeder_is_idempotent_and_contains_only_the_five_official_roles`. Os skips cobrem testes que exigem destinos MySQL específicos, inclusive os sete testes de reconciliação quando não recebem a variável de isolamento. Não foram executados testes contra bancos MySQL preexistentes de outros validadores.

Ocorrência: na primeira execução geral (190 testes/5.230 assertions), antes dessa exclusão, um teste legado chamou RoleSeeder duas vezes exclusivamente no SQLite em memória. Isso contrariou a restrição de não executar seeders e foi comunicado ao usuário; não houve efeito no original nem nas cópias MySQL. O JUnit final contém a nova execução sem esse teste.

Houve duas correções no código de teste/validação durante o ensaio: reinicialização da sessão ao alternar atores HTTP, para não reutilizar o hash de sessão do administrador; e tratamento da representação escapada de aspas em GENERATION_EXPRESSION no information_schema. As últimas execuções acima passaram. Os logs anteriores foram preservados para rastreabilidade.

## MySQL 5.7.44: pendência explícita

**Compatibilidade em execução com MySQL 5.7.44 ainda não comprovada.** Somente MySQL 8.4.3 está instalado no Laragon. Não houve instalação de servidor, alteração de outros bancos ou teste na HostGator.

A solução usa colunas virtuais indexadas e RENAME INDEX, disponíveis no MySQL 5.7, em vez dos índices funcionais do MySQL 8. A análise é sustentada pelo [manual de índices em colunas geradas](https://docs.oracle.com/cd/E17952_01/mysql-5.7-en/create-table-secondary-indexes.html), pelo [manual de ALTER TABLE](https://dev.mysql.com/doc/refman/5.7/en/alter-table.html) e pela [limitação de ADD VIRTUAL combinado com outras ações](https://docs.oracle.com/cd/E17952_01/mysql-5.7-en/innodb-online-ddl-operations.html). Isso não substitui ensaio real. Permanecem pendentes instalação/fluxos em 5.7.44, InnoDB Dynamic/Barracuda, large_prefix, página/limites de índice, privilégios TRIGGER, configuração de binary log e definers no ambiente de destino. O dump original contém índices funcionais: não importar esse dump diretamente em MySQL 5.7; o transporte precisa de dump do esquema já convertido, ensaiado nesse servidor.

## Plano de recuperação e rollback

1. **Estado atual:** não há rollback a fazer no original, pois ele não foi modificado. Basta manter a aplicação no banco original; conservar backup e cópia reconciliada para revisão.
2. **Antes de eventual etapa 2:** obter aprovação explícita, suspender gravações/filas e realizar novo backup/snapshot, porque o banco pode ter mudado desde este ensaio. Conferir identidade, hashes dos arquivos e condições estruturais novamente. O ensaio não autoriza execução automática.
3. **Aplicação futura:** repetir adoção transacional da allowlist de 89 e execução por caminhos explícitos. Não apagar/editar os dois registros originais. Não executar os testes com fixtures no banco original; nele, fazer apenas verificações de preservação e smoke tests de leitura/autenticação autorizada.
4. **Falha durante DDL:** parar imediatamente, preservar banco parcial/logs e inspecionar os objetos já criados. Não executar migrate:rollback/reset/fresh, não remover tabelas e não presumir rollback transacional de DDL. Coluna/índice temporário de conversão eventualmente presente exige plano específico após inspeção.
5. **Recuperação integral:** restaurar o backup pré-etapa-2 em OUTRO banco vazio e autorizado, verificar checksum, todas as linhas/DDL/FKs/triggers e histórico antes de apontar a aplicação para ele. Preservar o banco com falha e a mesma APP_KEY para os dados cifrados. Essa restauração futura precisa de autorização; não foi realizada sobre o original. Não importar dump de criação sobre tabelas existentes.
6. **Histórico de adoção:** não usar down das 89 migrations. Se somente a transação de histórico falhar, o rollback dessa transação desfaz suas inserções. Após commit, qualquer retirada dos registros adotados precisa de plano específico e não desfaz o esquema.

Para conferir o backup, em PowerShell:

```powershell
$backupFile = 'C:\Users\alex1\AppData\Local\SPACEdev-backups\spacedev_reconcile_20261028_122217_5bd62f53\spacedev_test.sql'
Get-Item -LiteralPath $backupFile
Get-FileHash -LiteralPath $backupFile -Algorithm SHA256
php83 tools/schema-audit.php --verify
php83 artisan migrate:status --database=mysql
```

O status do comando acima ainda é o do ORIGINAL: duas registradas e as demais pendentes. Não executar migrate nele antes de autorização. `schema-audit --verify` confirmou novamente que seu DDL, triggers e histórico permanecem inalterados; os hashes integrais de linhas também foram conferidos pelo ensaio.

## Artefatos e limite de autorização

- Relatório estruturado: `docs/migration-reconciliation-rehearsal.json`.
- Programa de ensaio: `tools/rehearse-migration-reconciliation.php`. Aceita somente origem local e cria destino aleatório com prefixo protegido; não contém modo de reconciliação do original. `--resume` retoma exclusivamente a cópia registrada no relatório e exige backup e origem inalterados.
- Migration aditiva e testes: arquivos citados acima e `tests/Feature/MigrationReconciliationMysqlTest.php`.
- Backup, checksum, logs de dump/restore/migrations/status e JUnit preservados fora do webroot.

**Execução interrompida ao final da etapa 1. Nenhum comando de etapa 2 foi executado.**
