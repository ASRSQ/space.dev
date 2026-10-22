> Referência histórica do pacote consolidado anterior. Para esta branch, use [revisão final e teste MySQL 5.7](mysql57-final-review.md). Não use o ZIP antigo com a cadeia individual.

# Correção do pacote de migrations — HostGator

## Diagnóstico

O log informado começa pelas três migrations padrão Laravel e falha no workflow porque `document_change_requests` não existe. No projeto local, a migration base **existe** e deve ser a primeira da lista. Portanto o log não corresponde à execução completa do pacote local em banco vazio. A hipótese principal é ausência da base no upload; também podem existir diretório de execução diferente, uso de `--path` ou histórico inconsistente. Não houve acesso SSH/FTP ao servidor nem ao banco `alexd919_dev`; o conteúdo remoto ainda precisa ser conferido. O diretório temporário do editor contém apenas `.env.example`, não uma cópia do pacote remoto.

A implementação do Laravel instalada ordena `getMigrationFiles()` pelo nome da migration. Esta é a ordem local validada:

| Ordem | Nome | Cria / depende de |
| --- | --- | --- |
| 1 | `0000_01_01_000000_create_space_domain.php` | Cria roles, users e as outras 41 tabelas base; depois acrescenta 79 FKs e 6 triggers. |
| 2 | `0001_01_01_000000_create_users_table.php` | Nesta versão cria somente password_reset_tokens e sessions. Users já pertence à base. |
| 3 | `0001_01_01_000001_create_cache_table.php` | cache e cache_locks; sem dependências do domínio. |
| 4 | `0001_01_01_000002_create_jobs_table.php` | jobs, job_batches e failed_jobs; sem dependências do domínio. |
| 5 | `2026_10_18_000003_add_project_documentation_workflow.php` | Depende de usuários, documentos/contextos, versões, solicitações e decisões; cria drafts, section_key e índices condicionais. |
| 6 | `2026_10_19_000001_add_task_deadlines.php` | Depende de tasks; acrescenta datas e índice de prazo. |

Não há colisões de nomes/timestamps ou outras migrations PHP nesse diretório. Autenticação fundamental (roles/users) está na base; por isso sua posição antes das migrations padrão é intencional. Dentro da base, todas as tabelas/índices são criados antes das FKs, resolvendo ciclos de versões. Renomear para colocar cache/jobs antes dela não resolve o upload incompleto e introduziria risco no histórico. **Nenhuma migration foi renomeada**, nem foram modificados registros de migrations.

## Pacote e conferência

Foi preparado `dist/spacedev-migration-repair.zip`, um **pacote complementar**, não uma distribuição inteira da aplicação. Ele inclui as seis migrations, `database/schema/space-baseline.json`, o helper de segurança documental, comando administrativo, seeders de papéis sem usuário de teste, manifest SHA-256, verificador e guias. Não inclui `.env`, credenciais, dumps, usuários ou vendor. Deve ser aplicado sobre a mesma versão da aplicação auditada.

Extraia na raiz correta do projeto, preservando subdiretórios, e confira antes de executar migrations:

```sh
pwd
php tools/check-installation-package.php
php artisan migrate:status
```

O verificador não carrega `.env`, não acessa o banco e retorna erro se faltar arquivo, se o conteúdo divergir do manifest ou se houver migration extra/renomeada. É uma conferência de integridade de transferência, não uma assinatura de autenticidade nem autorização para alterar banco existente. Para novos releases, revise todas as alterações e regenere o pacote localmente com `php tools/build-migration-repair-package.php`. Não misture versões de migrations.

## Como tratar `alexd919_dev`

Antes de executar qualquer correção, faça backup pelo painel e inspecione no phpMyAdmin:

```sql
SELECT migration, batch FROM migrations ORDER BY id;
SHOW TABLES;
SHOW TABLES LIKE 'users';
SHOW TABLES LIKE 'roles';
SHOW TABLES LIKE 'document_change_requests';
```

Se users existir, confira também `SHOW CREATE TABLE users`. A migration padrão Laravel antiga criava `password` e não o domínio correto (`password_hash`, role_id e status). O nome no log, isoladamente, não informa qual conteúdo desse arquivo foi executado.

- **Banco efetivamente vazio:** depois da conferência do pacote e requisitos do [guia de implantação](shared-hosting-installation.md), execute `php artisan migrate --force`, seed de papéis e criação interativa do administrador.
- **Somente as sete tabelas de infraestrutura e migrations, sem users/roles nem qualquer tabela de domínio:** se o histórico tiver exatamente as três migrations de infraestrutura bem-sucedidas, o pacote completo pode retomar com `php artisan migrate --force`. O Laravel executa a base pendente primeiro, apesar de ela ter nome anterior aos registros já aplicados. Esse cenário foi reproduzido isoladamente.
- **Users padrão antigo, domínio parcial, dados ou histórico que diga que a base já executou:** não retome automaticamente. A base recusa estruturas preexistentes para evitar sobrescrita. Prefira criar outro banco vazio pelo painel e instalar nele, conservando o original até verificar os dados. Se o banco parcial for comprovadamente descartável, a decisão de recriá-lo deve ocorrer após backup e conferência explícita. Não há script de exclusão automática neste pacote.
- **Banco já funcional:** não aplique a base novamente, não renomeie migrations executadas e não apague registros do histórico. A adoção de uma base em instalação antiga requer comparação própria e procedimento separado.

Não use `migrate:fresh`, `migrate:refresh`, desativação de FKs, exclusão de users ou inserção manual de registros de migrations como correção genérica. Não importe `spacedev_test`. O erro documental relatado ocorre em SELECT antes de qualquer DDL dessa migration, mas isso não dispensa inspecionar tentativas anteriores.

O workflow agora acusa explicitamente tabelas base ausentes antes da consulta. A migration de prazos também acusa tasks ausente antes de ALTER TABLE. Não foram modificados seus objetos ou regras de negócio. Instalações onde essas migrations já foram registradas não as reexecutam por causa da mudança de conteúdo.

## Validação

Executar localmente com PHP 8.3 e a conexão fonte apontando para `spacedev_test`:

```sh
php tools/validate-clean-install.php
php tools/validate-clean-install.php --infrastructure-first
php vendor/bin/phpunit
php tools/schema-audit.php --verify
```

Cada ensaio cria um novo banco aleatório isolado. O segundo instala primeiro apenas as três migrations de infraestrutura e então retoma a cadeia completa. Os relatórios são `docs/clean-install-validation.json` e `docs/infrastructure-resume-validation.json`; registram a ordem efetivamente executada, comparação de esquema e verificações funcionais. Dados de teste são desfeitos por transação. O banco local de origem não é migrado. A compatibilidade do MySQL e a permissão para triggers da conta HostGator ainda devem ser verificadas no destino.

Resultados executados em 20/10/2026:

- Instalação vazia: MySQL 8.4.3, seis migrations concluídas na ordem acima, 53 tabelas e 6 triggers, zero diferenças nas 45 tabelas de domínio e zero registros de aplicação após migrate; 19 verificações funcionais aprovadas, com rollback dos registros temporários.
- Retomada após infraestrutura: mesmos esquema e verificações aprovados; histórico preservou os três registros iniciais e acrescentou base, documentação e prazos, sem editar registros anteriores.
- PHPUnit: 133 testes, 4.940 assertions, 8 skips dos testes MySQL antigos que exigem outra instância específica; nenhuma falha.
- Arquivo ZIP extraído em diretório temporário: verificação positiva; remoção simulada da base e alteração simulada do JSON foram rejeitadas com exit code 1. O pacote original não foi alterado por esses testes.
- `schema-audit.php --verify`: DDL, triggers e histórico de `spacedev_test` inalterados. Não houve alteração de banco remoto.
