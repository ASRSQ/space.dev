# Revisão final e teste isolado na HostGator

Branch: `refactor/individual-laravel-migrations`. Nenhum commit, push, merge ou
deploy foi executado durante a revisão.

## Resultado da revisão

`git status --short`, `git diff --check` e `git diff --stat` foram executados.
A primeira conferência registrou 12 arquivos rastreados alterados/removidos,
93 inserções e 57 remoções; o stat não inclui os arquivos não rastreados.
As correções desta revisão acrescentam o modo de teste sem CREATE DATABASE,
proteções do Git e este procedimento. O diff deve ser revisto novamente antes
de preparar o commit. O índice Git estava vazio.

Foram revisadas as 89 migrations novas, incluindo contagem de Schema::create,
FKs, triggers, nomes, SQL e comparação com o inventário já validado. Ordem:

1. `0000_01_01_*`: 43 tabelas base e seus índices, incluindo users uma vez.
2. `0000_01_02_*`: 79 FKs em 40 migrations, após todas as tabelas/índices.
3. `0000_01_03_*`: seis triggers.
4. `0001_*`: sete tabelas de infraestrutura em três migrations.
5. `2026_10_18_000003_*`: drafts, quatro FKs, seções e duas chaves condicionais.
6. `2026_10_19_000001_*`: datas e índice de prazo de tarefas.

Há 94 migrations ativas. A consolidada original está fora de database/migrations,
em docs/migration-refactor-original, e não é descoberta pelo migrator padrão.
O baseline JSON permanece versionado como referência e não é executado.
Não passar o diretório de referência por --path nem restaurar o arquivo antigo.

O resultado esperado é 53 tabelas, 172 índices, 83 FKs e seis triggers.
O maior índice declarado tem 1020 bytes; o destino precisa de InnoDB DYNAMIC,
Barracuda, innodb_large_prefix habilitado e páginas de pelo menos 16KB.
O preflight exige essas condições sem tentar alterá-las globalmente.

Não foram encontrados padrões de chaves privadas ou tokens reais na varredura
dos arquivos candidatos. As migrations contêm estrutura e comentários, sem
linhas do banco. O fixture SQL rastreado é schema de testes, sem INSERTs.
O .env.example contém placeholders/null, não credenciais reais.
Arquivos .env, ZIPs de dist, JUnit XML, logs, dados da instância descartável e
o patch/checkpoint local estão ignorados; não usar git add -f. Os relatórios JSON
de validação são evidências intencionais, sem senhas. A migration original
arquivada é código de referência, não um artefato executável da instalação.

MySQL 5.7.44 suporta os recursos utilizados: JSON, SIGNAL, colunas virtuais e
índices únicos nessas colunas. Não há índices funcionais MySQL 8, colunas
invisíveis ou CHECKs na cadeia ativa. As restrições antigas sobre cascatas em
bases de colunas virtuais indexadas foram removidas em 5.7.14; a correção do bug
de atualização de índice virtual por cascata consta em 5.7.27. Isso sustenta a
análise estática para 5.7.44, mas não equivale a aprovação real:

- [Manual MySQL 5.7](https://downloads.mysql.com/docs/refman-5.7-en.pdf)
- [Bug 93670, corrigido em 5.7.27](https://bugs.mysql.com/bug.php?id=93670)

MySQL 5.7.44 e o ambiente HostGator **ainda não foram testados**. O comparador
normaliza larguras de exibição de inteiros e os defaults referenciais equivalentes
RESTRICT/NO ACTION do InnoDB; não ignora nomes, tipos/ranges, índices, ações
CASCADE/SET NULL, expressões ou corpos dos triggers. Divergências adicionais
devem interromper a aprovação e ser revisadas, nunca ignoradas automaticamente.

## Permissões e evidência local

O modo local antigo cria bancos e depende da referência. Para hospedagem foi
adicionado --existing-empty-database: ele não executa CREATE/DROP DATABASE,
CREATE USER, GRANT, SET GLOBAL nem acessa spacedev_test. O banco é provisionado
previamente pelo cPanel e precisa estar vazio. O nome deve ser exatamente
ACCOUNT_spacedev_mig57_test, podendo terminar em _NUMERO para novas tentativas.
DB_DATABASE e SELECT DATABASE() precisam coincidir com o argumento.

São necessárias permissões somente nesse banco: SELECT, INSERT, UPDATE, DELETE,
CREATE, ALTER, INDEX, REFERENCES, DROP e TRIGGER. DROP é usado na reaplicação
controlada das duas incrementais, exclusivamente no banco descartável.

O novo modo foi exercitado em uma instância MySQL 8.4.3 separada, sem binary
logging, com usuário que recebeu somente esses privilégios no banco de teste.
Passaram a instalação, 19 checks funcionais e 37 checks de integridade, e a
segunda execução foi recusada por banco não vazio. O usuário temporário foi
removido após a execução. Esse teste não aprova MySQL 5.7.

Se log_bin=ON e log_bin_trust_function_creators=OFF, a criação de triggers pode
exigir SUPER. O preflight interrompe antes de DDL nesse caso. Solicitar ao
provedor uma configuração que permita as triggers; não conceder privilégios
globais à aplicação nem remover regras de integridade. Consulte o
[manual de privilégios](https://dev.mysql.com/doc/mysql-security-excerpt/5.7/en/privileges-provided.html).

## Publicação: comandos locais para o responsável executar após revisar

Os comandos abaixo não foram executados. A seleção explícita deixa de fora o
ZIP e o manifest antigos, ferramentas antigas de empacotamento, artefatos locais
e alterações preexistentes que não fazem parte do release individual.
Alguns arquivos selecionados já tinham mudanças locais: revisar o conteúdo
completo antes de aprová-los. O checkpoint original continua no disco.

PowerShell, na raiz do repositório:

```powershell
$php83 = 'C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe'
git switch refactor/individual-laravel-migrations
git diff --check
& $php83 tools/individual-installation-manifest.php
$reviewPaths = @(
  '.gitignore', 'README.md',
  'app/Support/DomainMigrationPlatform.php',
  'app/Support/ProjectDocumentationMigrationSafety.php',
  'database/migrations', 'database/schema/individual-installation-manifest.json',
  'docs/schema-inventory.json', 'docs/individual-migrations.md',
  'docs/mysql57-final-review.md', 'docs/individual-infrastructure-validation.json',
  'docs/individual-migrations-validation.json',
  'docs/database-installation-audit.md', 'docs/shared-hosting-installation.md',
  'docs/project-documentation-migration-recovery.md',
  'docs/hostgator-migration-repair.md',
  'docs/migration-refactor-original/0000_01_01_000000_create_space_domain.php',
  'tools/generate-individual-migrations.php', 'tools/individual-installation-manifest.php',
  'tools/individual-integrity-smoke.php', 'tools/validate-individual-migrations.php',
  'tools/clean-install-smoke.php', 'tools/schema-audit.php',
  'tests/Feature/InstallationMigrationDependenciesTest.php'
)
git add -- $reviewPaths
git diff --cached --check
git diff --cached --stat
git diff --cached --name-only
git diff --cached
# Somente após revisar o conteúdo preparado:
git commit -m "Refactor domain schema into individual Laravel migrations"
git push -u origin refactor/individual-laravel-migrations
```

Não prosseguir se a seleção contiver .env, bancos, ZIP, logs, XML de JUnit ou
artefatos locais. Não usar git add . ou git commit -a para esta entrega.

## Teste isolado na HostGator: comandos para execução posterior

1. No cPanel, criar **novo** banco ACCOUNT_spacedev_mig57_test e um usuário
   exclusivo com permissões apenas nele. Para nova tentativa, criar outro nome
   como ACCOUNT_spacedev_mig57_test_2. Não reutilizar o banco parcial da aplicação.
2. Usar checkout novo fora de public_html e sem vhost público. Substituir o
   caminho de PHP se a conta usar outro executável. Credenciais entram somente
   no .env privado desse checkout, nunca no shell, Git ou documentação.

SSH/Bash, somente depois da publicação e autorização do teste:

```bash
set -eu
umask 077
PHP83=/opt/cpanel/ea-php83/root/usr/bin/php
test -x "$PHP83"
"$PHP83" -v
mkdir -p "$HOME/spacedev-migration-test"
cd "$HOME/spacedev-migration-test"
git clone --branch refactor/individual-laravel-migrations --single-branch \
  https://github.com/ASRSQ/space.dev.git app
cd app
git branch --show-current
git rev-parse HEAD
"$PHP83" tools/individual-installation-manifest.php
# Usar Composer executado com PHP 8.3; COMPOSER_PHAR deve apontar para o PHAR real.
COMPOSER_PHAR="$HOME/bin/composer.phar"
test -f "$COMPOSER_PHAR"
"$PHP83" "$COMPOSER_PHAR" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
"$PHP83" "$COMPOSER_PHAR" check-platform-reqs --no-dev
test ! -e .env
cp .env.example .env
chmod 600 .env
```

Editar .env com o editor privado disponível, configurando APP_ENV=testing,
APP_DEBUG=false, DB_CONNECTION=mysql, host/porta/usuário/senha do **banco novo**,
DB_DATABASE=ACCOUNT_spacedev_mig57_test, DB_CHARSET=utf8mb4,
DB_COLLATION=utf8mb4_unicode_ci, SESSION_DRIVER=array, CACHE_STORE=array e
QUEUE_CONNECTION=sync. Deixar DB_URL vazio. Não copiar o .env de produção.
Não manter cache de configuração anterior.

```bash
"$PHP83" artisan key:generate --force
"$PHP83" tools/validate-individual-migrations.php \
  --existing-empty-database=ACCOUNT_spacedev_mig57_test \
  --expected-server=5.7.44 --preflight
# Depois de conferir o preflight: o próprio validador executa migrate e os testes.
"$PHP83" tools/validate-individual-migrations.php \
  --existing-empty-database=ACCOUNT_spacedev_mig57_test \
  --expected-server=5.7.44
cat storage/logs/individual-hosting-ACCOUNT_spacedev_mig57_test.json
```

Substituir ACCOUNT pelo prefixo real em todos os locais. O teste escreve dados
temporários e reaplica down/up somente nas duas incrementais, depois de confirmar
que o destino começou vazio. Não executar migrate separadamente antes dele.
O relatório só recebe result=PASS após toda a sequência. Não publicar o relatório
via web. Em falha, preservar log/relatório e não repetir no mesmo banco.
Não executar comandos destrutivos na aplicação existente nem alterar configurações
globais do servidor. A infraestrutura-primeiro pode ser testada em **outro** banco
novo acrescentando --infrastructure-first ao comando de validação.

Após o teste, revisar o relatório e a configuração efetiva do servidor antes de
declarar compatibilidade. Nenhum resultado desta revisão autoriza deploy.
