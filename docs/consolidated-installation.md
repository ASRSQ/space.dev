# Instalação futura e rollback — NÃO EXECUTADOS

Executar somente após homologação e autorização expressa de publicação. Este roteiro substitui os comandos dos dois relatórios anteriores. Não aplicar automaticamente em produção. Não executar seeds, key:generate, migrate:fresh, rollback/reset ou migrate genérico.

## Pré-requisitos e identificação

O diretório remoto real não foi fornecido. Os comandos abaixo usam `$HOME/spacedev` e document root apontando para seu `public/`; confirmar o caminho real antes de substituir APP_DIR. Se public_html contém cópias separadas de assets/index.php, este procedimento precisa ser adaptado em homologação: não presumir que atualizar public/ altera public_html. PHP web e CLI 8.3+, vendor compatível com o mesmo composer.lock, SSH habilitado, tar/unzip/sha256sum e espaço para duas cópias completas. Pausar cron e workers na janela; manutenção HTTP não bloqueia jobs externos.

HostGator documenta porta 2222 em hospedagem compartilhada e caminho PHP 8.3 abaixo: [SSH](https://suporte.hostgator.com.br/hc/pt-br/articles/30812824748051-Como-acessar-um-servidor-via-SSH), [PHP](https://suporte.hostgator.com.br/hc/pt-br/articles/30812660878867-Quais-s%C3%A3o-os-caminhos-para-a-execu%C3%A7%C3%A3o-de-comandos-em-um-Cron-Job). Confirmar disponibilidade na conta.

Antes de conceder permissões, consultar usuários por e-mails exatos confirmados e projeto por slug confirmado; verificar que cada consulta é única, conferir status/papel, membros da organização e vínculo ativo do projeto. Buscar SC-001 dentro desse projeto, conferir descrição/relator e chave efetiva da aplicação. Não executar UPDATE por nome parcial ou IDs copiados de outro ambiente. A instalação não concede acesso nem atribui tarefa alguma.

## Transferência futura (PowerShell)

Substituir USUARIO_CPANEL e SERVIDOR pelos dados da conta:

```powershell
scp -P 2222 .\dist\spacedev-consolidated.zip .\dist\spacedev-consolidated.zip.sha256 USUARIO_CPANEL@SERVIDOR:~/
ssh -p 2222 USUARIO_CPANEL@SERVIDOR
```

## Pré-verificação, backup e troca (Bash/SSH)

O dump deve ser feito pelo cPanel ou comando abaixo e restaurado com sucesso em um banco de homologação antes da publicação. Não colocar senha na linha de comando nem enviar dumps para diretório público. O roteiro mantém a árvore completa anterior, incluindo arquivos que não existiam antes da atualização; assim o rollback não deixa Resources novos ativos. O cPanel/document root deve apontar para APP_DIR/public e suportar a breve troca por rename. Não usar se APP_DIR for symlink ou se houver workers escrevendo durante a janela.

```bash
set -eu
umask 077
PHP83=/opt/cpanel/ea-php83/root/usr/bin/php
APP_DIR="$HOME/spacedev"             # confirmar caminho real
STAMP=$(date +%Y%m%d_%H%M%S)
STAGE="$HOME/spacedev-stage-$STAMP"
NEXT="$HOME/spacedev-next-$STAMP"
PREVIOUS="$HOME/spacedev-before-$STAMP"
DUMP="$HOME/spacedev-before-$STAMP.sql"
test -x "$PHP83"
test -d "$APP_DIR"
test ! -L "$APP_DIR"
test -f "$APP_DIR/artisan"
test -f "$APP_DIR/.env"
test ! -f "$APP_DIR/public/hot"
cd "$HOME"
sha256sum -c spacedev-consolidated.zip.sha256
mkdir "$STAGE"
unzip -q spacedev-consolidated.zip -d "$STAGE"
"$PHP83" "$STAGE/tools/check-consolidated-release.php"
cd "$APP_DIR"
"$PHP83" -v
"$PHP83" -r 'require "vendor/autoload.php"; echo "Vendor carregado\n";'
test "$(sha256sum composer.lock | cut -d ' ' -f 1)" = "$("$PHP83" -r '$m=json_decode(file_get_contents($argv[1]),true); echo $m["composer_lock_sha256"];' "$STAGE/consolidated-release-manifest.json")"
"$PHP83" artisan migrate:status
```

Parar se o schema remoto tiver divergências, migration parcial ou tabelas/coluna incremental preexistentes sem histórico coerente. Conferir via phpMyAdmin: `SELECT VERSION(); SHOW CREATE TABLE users; SHOW CREATE TABLE projects;` e estruturas novas. Exigir teste prévio em cópia MySQL 5.7 com dados. Não há preflight automático que autorize uma base desconhecida.

Após pausar workers/cron e confirmar backup de restauração testada, continuar **na mesma sessão Bash**, substituindo os três valores de conexão por valores confirmados da conta. `-p` solicita senha interativamente:

```bash
"$PHP83" artisan down
mysqldump --host=HOST_CONFIRMADO --user=USUARIO_BANCO_CONFIRMADO -p --single-transaction --quick --routines --triggers --hex-blob --no-tablespaces NOME_BANCO_CONFIRMADO > "$DUMP"
test -s "$DUMP"
sha256sum "$DUMP" > "$DUMP.sha256"
cp -a "$APP_DIR" "$NEXT"
unzip -oq "$HOME/spacedev-consolidated.zip" -d "$NEXT"
cd "$NEXT"
"$PHP83" tools/check-consolidated-release.php --dependencies
"$PHP83" artisan optimize:clear
"$PHP83" artisan filament:optimize-clear
"$PHP83" artisan migrate --path=database/migrations/2026_10_23_000001_add_organization_access_management.php --pretend --force
```

Se o cliente mysqldump não aceitar `--no-tablespaces`, usar cliente compatível previamente homologado ou backup cPanel; não contornar falha silenciosamente. `--single-transaction` requer tabelas InnoDB e não protege contra DDL concorrente; não executar outras migrations. Routines/triggers exigem privilégios adequados. Conferir dump completo/restaurável e código/schema antes de continuar. `--pretend` mostra DDL; não prova compatibilidade.

```bash
"$PHP83" artisan migrate --path=database/migrations/2026_10_23_000001_add_organization_access_management.php --force
"$PHP83" artisan filament:assets
cd "$HOME"
mv "$APP_DIR" "$PREVIOUS"
mv "$NEXT" "$APP_DIR"
cd "$APP_DIR"
"$PHP83" artisan optimize:clear
"$PHP83" artisan filament:optimize-clear
"$PHP83" artisan optimize
"$PHP83" artisan filament:optimize
"$PHP83" tools/check-consolidated-release.php --dependencies
"$PHP83" artisan migrate:status
printf 'Backup de codigo: %s\nDump: %s\n' "$PREVIOUS" "$DUMP"
```

Registrar caminhos fora do public e conservar backups. Conferir schema e contagens/atribuições contra snapshot homologado, permissões de arquivos, logs e assets antes de liberar. Se houve falha em migration ou rename, manter manutenção e usar diagnóstico/rollback abaixo; não executar `up` automaticamente num estado indefinido. Se a segunda mv falhar, a árvore anterior continua em PREVIOUS: restaurar seu nome antes de qualquer tentativa adicional.

Somente após verificações e decisão do operador autorizado:

```bash
"$PHP83" artisan up
```

Verificar `/up`, login, menus e consultas autorizadas em `/admin/tasks` e `/admin/problems`, assets sem 404, sem vazamento entre organizações e logs sem exceções. Retomar workers/cron após conferir que usam o caminho atual. Não criar usuários/tarefas nem alterar SC-001 para smoke em produção; esses testes pertencem à homologação.

## Rollback conservando dados

Não executar `migrate:rollback`: o down é bloqueado. Reter as cinco tabelas e a coluna nullable, inclusive se vazias. Código antigo deve ter sido testado com o schema aditivo em homologação. Falha parcial de DDL exige diagnóstico; rollback de arquivos não conserta DDL parcial. Se houve uso das novas restrições organizacionais, o código antigo pode conceder acesso mais amplo: manter manutenção e preferir correção adiante; não reabrir sem revisão de segurança.

Na mesma sessão, ou após recuperar APP_DIR/PREVIOUS exatos do registro, manter cron/workers pausados. Conservar storage atual para não perder uploads/sessões após o deploy; preservar a versão falha para diagnóstico:

```bash
cd "$APP_DIR"
"$PHP83" artisan down
test -d "$PREVIOUS"
test -f "$PREVIOUS/artisan"
cp -a "$APP_DIR/storage/." "$PREVIOUS/storage/"
FAILED="$HOME/spacedev-failed-$(date +%Y%m%d_%H%M%S)"
cd "$HOME"
mv "$APP_DIR" "$FAILED"
mv "$PREVIOUS" "$APP_DIR"
cd "$APP_DIR"
"$PHP83" artisan optimize:clear
"$PHP83" artisan filament:optimize-clear
"$PHP83" artisan filament:assets
"$PHP83" artisan optimize
"$PHP83" artisan filament:optimize
```

Verificar rotas, código anterior, schema retido e fronteiras de acesso antes de executar `artisan up` e retomar jobs. A substituição de árvore completa remove do caminho ativo os arquivos novos, preservando-os em FAILED. Não restaurar dump antigo automaticamente após novas escritas. Restauração de banco exige autorização específica, reconciliação dos registros posteriores e teste em banco separado; o dump é recuperação de desastre, não rollback rotineiro desta migration.

**Pendente:** todo este roteiro, restauração de backup e troca/retorno de árvores precisam de ensaio na homologação com a estrutura real da HostGator. Nenhum desses comandos remotos foi executado neste trabalho.
