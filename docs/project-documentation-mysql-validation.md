# Validação isolada da Central de Documentação em MySQL 8.4.3

O ambiente de validação dedicado usa `127.0.0.1:13317`, diretório de dados próprio e banco descartável `spacedev_documentation_migration_validation`. Nunca use `spacedev_test` para esta validação.

## Fixture anterior à migration

`tests/Fixtures/mysql/documentation_baseline.sql` define o schema-base controlado para o teste. Reproduz as tabelas de documentos, versões, change requests, decisões, projetos, categorias, usuários e papéis, incluindo FKs compostas entre o ponteiro da versão atual e sua entidade. Não reproduz dados reais. Algumas estruturas de suporte sem migration versionada no repositório são uma definição controlada, não uma reprodução garantida de todo histórico de constraints; compare com um dump aprovado antes de qualquer implantação real.

A fixture não cria `section_key`, índices funcionais de pendência ou tabelas de drafts. Esses objetos pertencem à migration sob teste.

## Procedimento seguro

1. Confirme independentemente `VERSION()`, `@@bind_address`, `@@port`, `@@datadir`, `DATABASE()` e a configuração PDO do processo. Aborte se qualquer valor divergir.
2. Confirme que o banco dedicado recém-criado está vazio. Aplique a fixture uma vez; em caso de erro, pare e inspecione somente leitura.
3. Rode `MySqlProjectDocumentationMigrationPreflightTest` antes da migration para verificar duplicidades pré-existentes e o bloqueio de um estado parcial controlado. O teste remove apenas o índice marcador que ele mesmo criou.
4. Execute exclusivamente a migration `2026_10_18_000003_add_project_documentation_workflow.php` e confirme sua linha na tabela `migrations` do alvo.
5. Execute novamente o mesmo caminho pelo runner Laravel para comprovar que o registro do runner não reaplica a migration.
6. Inspecione colunas, expressões em `information_schema.STATISTICS`, engine, índices e FKs. Índices pendentes devem ser únicos, ter expressão condicional `CASE ... status = 'pending' ...`, permitir múltiplas linhas resolvidas e rejeitar a segunda pendente por entidade.
7. Rode `MySqlProjectDocumentationValidationTest` no mesmo alvo. Seus testes transacionais cobrem publicação, integridade de versão, duplicidade, rollback protegido e FKs.
8. Opcionalmente, em validação autorizada apenas no banco descartável, execute `down()` sem feature data, confirme que somente objetos da migration foram revertidos e reaplique-a. Não faça isso em banco com dados úteis.
9. Rode os testes comuns usando PHP 8.3.16 e SQLite em memória.

As variáveis `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE` e `DB_URL` podem ser definidas somente no processo de teste; não altere `.env`. `DOCUMENTATION_MYSQL_VALIDATION=1` habilita as classes MySQL e `DocumentationMySqlValidationTarget` valida host, porta, banco, versão e diretório antes de qualquer teste que escreve.

## Limitações

SQLite valida lógica de domínio, mas não prova sintaxe/DDL funcional do MySQL, locks concorrentes, commits implícitos, FKs compostas, enums ou semântica de índices únicos. A integração neste alvo prova a migração e constraints no MySQL 8.4.3, mas os testes atuais não simulam duas sessões concorrentes sincronizadas nem uma falha de processo real entre cada DDL. Essa recuperação é coberta por estado parcial controlado que confirma que a migration aborta sem avançar; uma falha de servidor deve continuar exigindo diagnóstico manual.
