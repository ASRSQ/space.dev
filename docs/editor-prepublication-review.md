# Revisão dos editores antes da publicação — 23/10/2026

## Resultado

Implementação revisada e testes relacionados aprovados. Nenhuma operação remota foi executada. Nenhum registro real foi alterado nem houve migration destrutiva. A publicação ainda depende da conferência visual/JavaScript no navegador e dos registros específicos SC-001/Lauro no ambiente em que eles existem.

## Correções da revisão

- A página de erros não existia: havia somente o Model `Problem`, seus relacionamentos e migrations. Foi criado `ProblemResource`, com listagem, criação, edição e detalhes em `/admin/problems`. Seus campos correspondem ao esquema existente: projeto, título, descrição, gravidade e status. Relator é definido no servidor; projeto, relator e arquivamento não podem ser adulterados pelo formulário de edição. Nenhuma migration foi adicionada.
- Erros e tarefas compartilham `TaskMarkdown`, a prévia e a view de descrição. O comportamento Livewire do checklist foi extraído para `InteractsWithDescriptionChecklist`; não foi duplicado em cada página.
- A nova `ProblemPolicy` permite consulta/criação a administradores ativos e bloqueia edição em erros ou projetos arquivados. Foi registrada explicitamente no provider. As permissões existentes de tarefas permanecem iguais: desenvolvedores não acessam o painel administrativo.
- Checklists bloqueiam o registro e o projeto na transação, reautorizam a alteração e comparam o hash da descrição. O marcador é identificado pela árvore CommonMark; exemplos dentro de código não são checklists editáveis. Só o caractere correspondente muda. Conflitos mostram aviso e carregam a versão atual.
- A revisão encontrou risco de sobrescrita dos demais campos quando apenas a descrição era comparada. `ProtectsEditorFromStaleUpdates`, compartilhado pelos dois editores, agora verifica todos os campos editáveis do formulário, incluindo responsável e prazos em tarefas. O token é bloqueado pelo Livewire, e o registro é relido do banco após salvar para normalizar datas/tipos. Campos de status externos ao formulário de tarefas não entram no token.
- Markdown remove HTML fornecido pelo usuário, bloqueia links inseguros e limita o aninhamento a 100 níveis. Apenas o renderer da aplicação gera atributos Livewire, com linha numérica e hash hexadecimal. Blocos de código recebem a classe da linguagem; não foi acrescentado realce sintático.

## Evidências automáticas

| Validação | Resultado |
|---|---|
| MySQL local do Laragon 8.4.3, porta 3306, PHP 8.3.16 | 13 testes, 242 assertions, aprovados |
| TaskResourceTest + TaskProjectDomainRelationshipsTest + FilamentPanelAuthorizationTest | 54 testes, 462 assertions, aprovados em SQLite em memória |
| Criação, edição e detalhes de tarefas e erros via HTTP/Livewire | Aprovados no MySQL com o esquema real instalado pelas migrations |
| Marcar/desmarcar checklist e abrir novo componente/requisição | Estado persistido e Markdown/CRLF preservados |
| Títulos, listas, tabela, negrito, itálico, links, quebras e código | Renderização conferida nos detalhes |
| Usuário com consulta permitida e edição negada | Inputs desabilitados; chamadas forjadas de checklist e save retornam 403 |
| Desenvolvedor | Listagem, criação, detalhes e edição retornam 403 |
| Projeto arquivado após abrir a página | Alteração do checklist retorna 403 |
| Dois componentes com a mesma revisão e formulário antigo | Segunda escrita é recusada; primeira alteração é preservada |
| Conexões MySQL concorrentes | Lock de linha impede segunda escrita; timeout 1205 esperado |
| Duas edições de outros campos e salvamentos repetidos com datas | Conflito detectado; salvamento subsequente da sessão atual funciona |
| Payloads script, SVG, eventos HTML, Alpine/Livewire, javascript e data URL | Removidos/bloqueados; somente eventos de checklist gerados pela aplicação permanecem |
| Vite build | Concluído, 59 módulos; manifest e assets gerados em public/build |
| Pint e git diff --check | Conferidos nos arquivos desta entrega |

O runner `tools/validate-editors-mysql.php` exige `APP_ENV=local`, host MySQL loopback e ausência de DB_URL/socket alternativo. Cria um banco NOVO `spacedev_editor_validation_*`, instala o esquema somente nele e executa cada teste em transação revertida. Os bancos isolados foram mantidos para inspeção; zero registros de aplicação permaneceram após os testes. Não executa DROP nem rollback de migrations.

O relatório [editor-mysql-validation.json](editor-mysql-validation.json) registra o nome exato do último banco, versão, exit code, ausência de registros residuais e comparação dos hashes dos registros de tasks/users/project_memberships/problems do banco fonte antes/depois. Esses registros permaneceram iguais. Os dados fonte só foram consultados. O MySQL local estava parado e foi iniciado para essa validação; permaneceu disponível no final.

Os erros encontrados no desenvolvimento dos novos testes foram corrigidos antes da execução final. Não houve falha preexistente nas suítes relacionadas concluídas. Não se afirma que toda a suíte do repositório ou MySQL 5.7 da hospedagem tenham sido executados.

## SC-001 e Lauro

No banco local configurado (`spacedev_test`), a consulta por título contendo `SC-001` e por nome contendo `Lauro` não encontrou registros. O esquema de tarefas não possui coluna de código separada. Assim, não foi possível confirmar nem alterar esses registros reais. O teste isolado cria uma tarefa `SC-001`, um usuário `Lauro` ativo e vínculo ativo com o projeto: atribuição, seleção ao reabrir a edição, salvamento sem mudança, exibição nos detalhes e preservação após revogação do vínculo passaram. Isso confirma o fluxo, sem afirmar a elegibilidade de registros ausentes.

## Assets e dependências

Não há dependência PHP nem biblioteca de editor nova. A interface utiliza o MarkdownEditor nativo instalado. `package.json` e `composer.lock` não mudaram. Faltavam `node_modules` e `package-lock.json`; foram instaladas as dependências já declaradas e gerado `package-lock.json` para repetibilidade.

Os ajustes PHP/Blade não introduzem CSS ou JavaScript próprios; a classe `fi-prose` já pertence ao tema. O hash do tema Filament permaneceu `theme-BoP-RfUq.css`. Ainda assim, foi executado o build de produção do projeto inteiro, e o pacote de atualização inclui o manifest e seus assets. Publique `public/build` junto com ele. O editor nativo também precisa de `public/js/filament/forms/components/markdown-editor.js`: `artisan filament:assets` publica os assets a partir de vendor sem Node na hospedagem. Node/npm não são necessários em produção.

## O que ainda exige navegador/ambiente de destino

1. Em homologação, abra `/admin/tasks` e `/admin/problems`, crie descrições com tabela, código e checklist, confira a prévia ao sair do editor e salve. Valide desktop/mobile, tema claro/escuro e legibilidade de tabelas/código.
2. Clique no checkbox nos detalhes, confira o indicador de salvamento, pressione F5 e reabra o registro. Os testes de HTTP/Livewire validam persistência, mas não executam o JavaScript/Alpine de um navegador real.
3. Abra duas abas, altere uma delas e tente salvar/marcar na outra. Os testes automatizados cobrem revisões antigas e locks MySQL, mas não são um teste de carga ou dois navegadores sincronizados.
4. Confira ausência de erros JavaScript, requisições Livewire 419/403 inesperadas e assets 404. Teste sessão e CSRF no domínio HTTPS real.
5. No ambiente que contém SC-001 e Lauro, consulte status do usuário, vínculo ativo e estado do projeto. Faça qualquer teste de alteração apenas em homologação/cópia autorizada; não alterar dados reais por causa desta revisão.
6. Não existe papel nativo somente-leitura no painel: o teste de leitura sem edição foi feito restringindo Gate. Desenvolvedores continuam sem acesso ao painel; essa regra não foi ampliada.

## Arquivos da entrega

- `app/Support/TaskMarkdown.php`
- `app/Services/TaskAssignmentService.php`
- `app/Filament/Resources/Tasks/TaskResource.php` e páginas `EditTask`, `ViewTask`, `TaskKanban`
- `app/Filament/Concerns/InteractsWithDescriptionChecklist.php`
- `app/Filament/Concerns/ProtectsEditorFromStaleUpdates.php`
- `app/Filament/Resources/Problems/ProblemResource.php` e páginas `ListProblems`, `CreateProblem`, `EditProblem`, `ViewProblem`
- `app/Policies/ProblemPolicy.php`, `app/Providers/AppServiceProvider.php`
- `resources/views/filament/tasks/description.blade.php` e `description-preview.blade.php`
- `tests/Feature/Filament/TaskResourceTest.php`, `tests/Feature/MySqlEditorValidationTest.php`
- `tools/validate-editors-mysql.php`, `tools/build-editor-release.php`, `tools/check-editor-release.php`
- `package-lock.json`, este relatório e `docs/editor-mysql-validation.json`

As alterações preexistentes em infraestrutura/migrations não fazem parte do pacote de editores.

## Reproduzir localmente — PowerShell

Com MySQL do Laragon disponível e `.env` LOCAL já configurado:

```powershell
Set-Location 'C:\laragon\www\spacedev'
$editorPhp = 'C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe'
& $editorPhp tools/validate-editors-mysql.php
& $editorPhp vendor/phpunit/phpunit/phpunit tests/Feature/Filament/TaskResourceTest.php tests/Feature/TaskProjectDomainRelationshipsTest.php tests/Feature/FilamentPanelAuthorizationTest.php
npm.cmd ci
npm.cmd run build
& $editorPhp tools/build-editor-release.php
```

O último comando prepara `dist/spacedev-editors.zip` e `.sha256`. O pacote é complementar à instalação atual, não é um instalador novo. Sua allowlist inclui somente o código dos editores, relatório, lock npm, verificador e os assets Vite. Não contém `.env`, vendor, migrations, dados, logs ou scripts de testes MySQL.

## Publicação posterior na HostGator — NÃO executada

O caminho remoto não foi inspecionado. O roteiro assume `$HOME/spacedev`, conforme a estrutura recomendada no guia existente; ajuste SOMENTE `APP_DIR` se a instalação atual estiver em outro diretório. O document root deve continuar apontando para `public/`, preservando a configuração Apache existente. O domínio deve usar PHP 8.3+ compatível com vendor também no cPanel. Este é um update da aplicação já instalada, com o mesmo vendor/composer.lock e esquema atual, não uma instalação do zero.

A HostGator documenta SSH compartilhado na porta 2222 e o executável `/opt/cpanel/ea-php83/root/usr/bin/php`. Confira a disponibilidade na conta. Se SSH não estiver habilitado, habilite-o pelo suporte antes de usar os comandos; não crie um instalador público.

Envie o artefato sem expô-lo pela web. No PowerShell, substitua `USUARIO_CPANEL` e `HOST_DO_SERVIDOR` pelos valores reais:

```powershell
scp -P 2222 .\dist\spacedev-editors.zip .\dist\spacedev-editors.zip.sha256 USUARIO_CPANEL@HOST_DO_SERVIDOR:~/
ssh -p 2222 USUARIO_CPANEL@HOST_DO_SERVIDOR
```

No SSH/Bash, depois de validar em homologação e fazer o backup habitual da hospedagem:

```bash
set -eu
PHP83=/opt/cpanel/ea-php83/root/usr/bin/php
APP_DIR="$HOME/spacedev"
RELEASE="$HOME/spacedev-editors.zip"
STAGE="$HOME/spacedev-editor-stage-$(date +%Y%m%d_%H%M%S)"
BACKUP="$HOME/spacedev-editor-backup-$(date +%Y%m%d_%H%M%S).tar.gz"

test -x "$PHP83"
"$PHP83" -v
cd "$HOME"
sha256sum -c spacedev-editors.zip.sha256
mkdir "$STAGE"
unzip -q "$RELEASE" -d "$STAGE"
"$PHP83" "$STAGE/tools/check-editor-release.php"

cd "$APP_DIR"
test -f artisan
test -f .env
test -f vendor/autoload.php
test ! -f public/hot
"$PHP83" -r 'require "vendor/autoload.php"; echo "Autoload e plataforma OK\n";'

tar -czf "$BACKUP" app resources/views public/build
"$PHP83" artisan down
unzip -oq "$RELEASE" -d "$APP_DIR"
"$PHP83" tools/check-editor-release.php
"$PHP83" artisan filament:optimize-clear
"$PHP83" artisan config:clear
"$PHP83" artisan route:clear
"$PHP83" artisan view:clear
"$PHP83" artisan filament:assets
"$PHP83" artisan optimize
"$PHP83" artisan filament:optimize
"$PHP83" artisan up
```

Se algum comando falhar, `set -e` interrompe o roteiro; não continuar com o upload nem liberar a aplicação sem corrigir a causa ou restaurar o código. O backup fica fora da raiz pública. O ZIP conserva `.env`, APP_KEY, storage e banco existentes. Não exige `composer install/update`, pois não há alteração de dependências PHP; o autoload PSR-4 padrão encontra as classes novas. Se a instalação remota tiver habilitado classmap autoritativo por personalização, regenere-o com o Composer local à conta usando PHP 8.3 e `dump-autoload --no-dev --optimize --no-scripts` antes de executar Artisan.

Não executar `migrate`, `migrate:fresh`, `migrate:refresh`, rollback, seed, `key:generate` ou testes de integração em produção para esta atualização. A atualização pressupõe as tabelas `tasks` e `problems` já existentes; eventual divergência do banco remoto exige diagnóstico separado. Não enviar o pacote de reparação de migrations junto com este pacote.

Confira `/up`, `/admin/tasks`, `/admin/problems`, assets do MarkdownEditor e logs após publicar. Para preservar a restrição sobre dados reais, valide mudanças de checkbox/atribuição apenas em homologação com registros sintéticos. O procedimento não foi executado no servidor e não certifica sua versão MySQL, limites de memória, permissões ou configuração PHP web.

Referências: [SSH HostGator](https://suporte.hostgator.com.br/hc/pt-br/articles/30812824748051-Como-acessar-um-servidor-via-SSH), [caminhos PHP HostGator](https://suporte.hostgator.com.br/hc/pt-br/articles/30812660878867-Quais-s%C3%A3o-os-caminhos-para-a-execu%C3%A7%C3%A3o-de-comandos-em-um-Cron-Job), [deploy Filament 5](https://filamentphp.com/docs/5.x/deployment).
