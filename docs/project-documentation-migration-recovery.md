# Central de Documentação: implantação e recuperação no MySQL 8.4

A migration `2026_10_18_000003_add_project_documentation_workflow.php` executa várias etapas DDL. No MySQL, cada DDL pode confirmar implicitamente; trate uma falha como possível estado parcial.

## Aplicação

1. Faça backup consistente e confirme que a restauração foi testada.
2. Suspenda gravações de documentos e contextos técnicos durante a janela.
3. Verifique solicitações pendentes duplicadas nas duas tabelas. A migration aborta com mensagem clara e não corrige duplicidades automaticamente.
4. Verifique que não existe nenhum objeto pertencente à migration: os índices `uq_dcr_pending_document` e `uq_tccr_pending_context`, os índices de seção, as colunas `section_key` e as duas tabelas de draft.
5. Aplique a migration pelo procedimento aprovado e valide todo o schema antes de reabrir gravações.

Os índices pendentes são índices funcionais únicos sobre `CASE WHEN status = 'pending' THEN <entity_id> ELSE NULL END`. No MySQL, múltiplos valores `NULL` são permitidos em índice único; assim, solicitações resolvidas mantêm seu histórico e somente uma pendente por entidade pode existir.

## Diagnóstico após falha

Não repita `up()` nem remova objetos automaticamente. Interrompa gravações, registre o estado e compare cada objeto com a definição exata da migration.

```sql
SELECT migration, batch
FROM migrations
WHERE migration = '2026_10_18_000003_add_project_documentation_workflow';

SELECT TABLE_NAME, TABLE_TYPE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'document_change_requests', 'technical_context_change_requests',
    'documents', 'technical_contexts', 'document_drafts', 'technical_context_drafts'
  );

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('documents', 'technical_contexts')
  AND COLUMN_NAME = 'section_key';

SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, EXPRESSION
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND INDEX_NAME IN (
    'uq_dcr_pending_document', 'uq_tccr_pending_context',
    'uq_documents_project_section', 'uq_tech_ctx_project_section'
  )
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME,
       REFERENCED_COLUMN_NAME, ORDINAL_POSITION
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('document_drafts', 'technical_context_drafts');
```

A ausência do registro em `migrations` junto a um ou mais objetos novos indica execução parcial ou intervenção externa. `ProjectDocumentationMigrationSafety` bloqueia a repetição se qualquer objeto próprio estiver presente; também verifica objetos esperados antes do rollback. Não transforme esse bloqueio em `IF NOT EXISTS`, pois isso poderia aceitar índices ou colunas incompatíveis.

Após diagnóstico, DBA e responsável pelo produto devem decidir explicitamente entre restaurar backup ou preparar uma retomada para frente revisada. Não execute `DROP`, `ALTER ... DROP` nem altere `migrations` para destravar uma tentativa. A migration não implementa retomada automática.

## Rollback protegido

`down()` só prossegue se a instalação estiver completa e não houver linhas em `document_drafts`/`technical_context_drafts` nem `section_key` preenchida. Caso contrário, aborta antes de DDL para preservar conteúdo identificável. Para reverter depois do uso, suspenda gravações, faça backup, exporte drafts e o mapeamento de seções, valide a restauração e obtenha autorização explícita. O procedimento operacional deve preservar essa exportação; a migration não arquiva os dados automaticamente.

## Colunas legadas e rollback

As colunas `document_change_requests.pending_document_id` e `technical_context_change_requests.pending_technical_context_id` são objetos legados inesperados. `ProjectDocumentationMigrationSafety::assertNoPartialInstallation()` detecta qualquer uma delas e aborta informando tabela e coluna. A proteção não remove, altera nem corrige esses objetos. Inspecione a origem e compare o schema real com a migration antes de planejar uma recuperação explicitamente aprovada.

Execute rollback somente em uma janela de manutenção sem gravações. Antes de qualquer rollback aprovado, exporte e valide um backup recuperável dos rascunhos e das associações de seção. A proteção da migration é uma verificação adicional; não substitui backup, validação de restauração nem um plano de recuperação.
