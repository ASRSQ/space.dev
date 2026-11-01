# Gestão de acesso — modelo proposto e implementação

> Documento da implementação original. Para entrega, testes e instalação consolidados, seguir [consolidated-prepublication-review.md](consolidated-prepublication-review.md) e [consolidated-installation.md](consolidated-installation.md). Os comandos e o ZIP de acesso abaixo são históricos e não são o pacote atual.

## Diagnóstico prévio

O ambiente local configurado usa `spacedev_test`. Na consulta somente de leitura, usuários 3/4 eram Carol Contributor/David Programmer, a tarefa 3 era “Configurar flags de criptografia para tokens do GitHub” no SPACE Core Engine; Alex, Lauro e SPACE Commerce não existem nesse banco. O vínculo do usuário 4 ao projeto 1 estava revogado. Nenhum desses registros será tratado como o caso real nem alterado para reproduzi-lo.

`users.role_id` define um papel global, e `project_memberships` já guarda papel contextual, concedente, revogador e histórico. O Select exige conta e vínculo ativos. Não há interface de membros/usuários; o painel e as Policies de tarefas/problemas/documentação restringiam tudo a administradores globais. Logo, o vínculo é a condição a verificar no ambiente real; localmente só é possível reproduzir com dados sintéticos.

## Modelo apresentado antes de executar migrations

Preservar `roles`, `users.role_id` e `project_memberships`. Não duplicar vínculos de projeto nem instalar uma biblioteca RBAC. Papéis combinados (`developer_editor`) vivem no vínculo contextual, permitindo programador/editor juntos sem converter a conta em administrador global. Papéis legados (`owner`, `lead`, `tech_lead`, `contributor`, `reviewer`) terão mapeamento explícito.

Acrescentar organizações administrativas com membros e administradores; `projects.organization_id` será opcional, sem preencher registros antigos automaticamente. Equipes serão grupos dentro da organização, com membros, responsável e associação a projetos. Equipes servem para organização e filtro: não concedem acesso implícito ao projeto. A participação autorizada continua tendo uma única fonte, `project_memberships`. Essa separação é necessária para permitir várias equipes na mesma organização e não equivale a tenants do SPACE Commerce.

Migration incremental: tabelas `organizations`, `organization_memberships`, `teams`, `team_memberships`, `project_team`; FK opcional de projeto para organização. Tipos simples compatíveis com MySQL 5.7+, FKs, índices únicos e sem exclusão/conversão dos registros existentes. Rollback destrutivo será bloqueado. A migration será executada apenas em banco novo isolado nos testes; não no banco real/local existente.

Administradores globais têm autorização explícita entre organizações. Administradores de organização administram seu escopo; os demais precisam de vínculo ativo tanto com a organização (quando definida) quanto com o projeto. Revogação preserva histórico, descrições e atribuições antigas. Editor/leitor não distribuem tarefas. Aprovações documentais continuam com os administradores globais, sem ampliação automática para líderes de projeto/organização.

## Implementação e matriz de acesso

| Perfil efetivo | Consulta | Alterações e gestão | Aprovação |
|---|---|---|---|
| Admin/super-admin global ativo | Todos os escopos; autorização global explícita | Usuários, organizações, equipes, projetos, participantes e tarefas | Mesma regra anterior da documentação |
| Administrador de organização | Organização ativa e seus projetos | Informações da organização, membros já conhecidos, equipes e gestão dos projetos da organização | Não recebe aprovação automaticamente |
| Administrador de projeto: owner/lead/tech_lead | Projetos com vínculo ativo | Metadados, participantes, distribuição/arquivamento de tarefas; não administra contas globais | Não recebe aprovação automaticamente |
| Programador: developer/contributor | Projetos autorizados | Cria tarefas, atualiza as próprias tarefas atribuídas ou relatadas, registra problemas, cria rascunhos e propõe conteúdo | Não |
| Editor contextual | Projetos autorizados | Edita rascunhos e submete alterações de conteúdo; não distribui tarefas operacionais | Não |
| Programador e editor: developer_editor | Projetos autorizados | Capacidades combinadas no mesmo vínculo, sem promoção global | Não |
| Leitor global ou contextual; reviewer legado | Somente projetos autorizados | Sem alterações; leitor global continua somente leitura mesmo com um papel contextual mais amplo | Não |

Os cinco papéis oficiais do RoleSeeder foram preservados (`super-admin`, `admin`, `developer`, `user`, `viewer`). Slugs legados, como `programmer`, não são renomeados nem convertidos: o papel contextual define as capacidades no projeto. O cadastro em Usuários permite selecionar papéis globais já existentes; os papéis contextuais são gerenciados em Membros dos projetos.

Criar projetos continua restrito à administração global. Nomear proprietário não cria vínculo ativo implicitamente. Para vincular projeto legado a uma organização, primeiro inclua seu proprietário e todos os participantes ativos na organização; o serviço rejeita vínculos incompatíveis e transferências entre organizações. Administradores de organização podem gerenciar membros existentes, mas não explorar/vincular contas globais desconhecidas; a inclusão inicial desses usuários é feita por administrador da plataforma.

Novos menus:

- Administração: Usuários, Organizações, Equipes, Papéis e permissões.
- Projetos: Projetos, Membros dos projetos, Tarefas, Erros e a Central de documentação já existente dentro do projeto.

Não foram criados menus duplicados para documentação nem um novo editor de conteúdo. Usuários, organizações e equipes usam componentes Filament existentes. A matriz é uma consulta às Policies para usuário/projeto selecionados; não é um formulário que concede privilégios. Documentos, contextos e prompts têm Policies explícitas; os fluxos de revisão existentes não foram alterados. Não foi acrescentada interface de prompts nem inventado um novo fluxo de aprovação de prompts. Exclusão física e arquivamento de conteúdo sem fluxo operacional permanecem negados.

## Correção da seleção de responsável

`TaskAssignmentService` agora mantém Select, diagnóstico e validação coerentes:

1. Conta ativa, papel global diferente de leitor, vínculo ativo com o projeto e papel operacional elegível (`owner`, `lead`, `tech_lead`, `contributor`, `developer`, `developer_editor`).
2. Quando o projeto tem organização, o usuário também precisa de participação ativa nessa organização não arquivada.
3. Gestores podem distribuir tarefas. Programadores só recebem a opção de atribuir uma tarefa nova a si mesmos ou deixá-la sem responsável; alteração posterior do responsável exige a Policy `assign` no servidor.
4. O responsável atual continua visível se perder elegibilidade, com indicação adequada. Em um formulário sem autorização de distribuição, aparece como atribuição atual somente para leitura.
5. Abrir e salvar outros campos não limpa o responsável. O token de concorrência do editor continua protegendo descrição, atribuição e demais campos editáveis.
6. Na edição da tarefa, o gestor encontra **Membros do projeto** e **Verificar responsável**. O diagnóstico diferencia conta inativa, vínculo de organização ausente, vínculo de projeto ausente/revogado e papel não operacional. O Kanban oculta criação para perfis sem essa permissão e aplica os mesmos filtros de projeto e responsável.

Em Membros dos projetos é possível pesquisar usuários elegíveis, conceder papéis, mudar papel, consultar capacidade de edição de conteúdo/registro de problemas, abrir tarefas atribuídas e revogar participação. Mudanças de papel revogam o vínculo anterior e criam outro; não sobrescrevem sua história. A revogação registra ator e data e não apaga comentários, tarefas, atribuições ou autoria.

## Gestão segura de contas

`UserAdministrationService` reautoriza todas as gravações, valida nome/e-mail/papel/estado e restringe a gravação a campos conhecidos. Senhas exigem confirmação, 12 caracteres, maiúsculas/minúsculas, número e símbolo; o cast existente gera o hash. Campos de senha são esvaziados ao abrir e após salvar/criar; hashes não são exibidos nem serializados. A senha em branco mantém o hash anterior. A redefinição é administrativa, sem envio de senha por e-mail nem credencial fixa.

Administradores comuns não atribuem super-admin nem editam contas super-admin. Ninguém pode mudar o próprio papel global ou desativar a própria conta. O último administrador ativo é protegido. Redefinição de senha, desativação e alteração de papel invalidam tokens de lembrança e sessões persistidas. Contas são desativadas/suspensas, sem exclusão física de usuários com histórico.

Equipes exigem membros ativos da mesma organização. O responsável é incluído na equipe e precisa ser substituído antes de remoção. Associação de equipe a projeto exige a mesma organização e autorização de gestão em ambos. O filtro de equipe está disponível em Usuários. Remover essa associação não revoga nem concede implicitamente participações em projetos.

## Validação automática

- Suíte de regressão completa: **184 testes, 5.214 assertions, zero falhas e 21 skips**. Os skips são testes MySQL com execução opt-in; não representam testes aprovados.
- Gestão de acesso em **MySQL local 8.4.3 / PHP 8.3.16**: **23 testes, 280 assertions, todos aprovados**, em banco aleatório isolado. O relatório [access-mysql-validation.json](access-mysql-validation.json) identifica o banco e comprova que os registros fonte permaneceram iguais e nenhum registro de aplicação ficou no destino após os testes.
- Cenário Alex → inclusão de Lauro → SC-001 → salvar → nova requisição → tarefa selecionada e exibida → Lauro atualiza a própria tarefa: aprovado com dados sintéticos, sem papel administrativo para Lauro.
- Criação/edição/desativação de usuários, redefinição de senha, autoelevação, inclusão de participantes, histórico, isolamento entre organizações, IDs de projeto manipulados, papéis de editor/leitor, equipes e preservação das regras de aprovação: testados automaticamente.
- Instalação completa com `--preserve-schema`: **95 migrations**, 58 tabelas, 50 tabelas de domínio comparadas, 92 FKs, 187 índices, seis triggers, zero diferenças de esquema e zero registros residuais. **19 verificações funcionais + 34 de integridade** aprovadas. Veja [individual-migrations-validation.json](individual-migrations-validation.json). O modo usado não executou migrations `down` destrutivas.
- Vite: build de produção concluído, sem novas dependências. Assets gerados em `public/build`. Pint e verificações de whitespace aplicados.

O teste da migration em SQLite preserva linhas preexistentes de usuários/projetos/tarefas, mantém `organization_id` nulo e verifica que o rollback destrutivo é recusado. No MySQL, as migrations foram executadas exclusivamente em bancos novos; o banco real/local configurado não foi migrado.

Durante a auditoria foi encontrado um defeito anterior no comparador DDL: o `AUTO_INCREMENT` gerado por inserções revertidas impedia o reconhecimento da expressão revisada de chaves pendentes. A normalização desse metadado foi antecipada, com dois testes de regressão que continuam detectando diferenças reais de armazenamento. Também foram atualizadas as contagens esperadas para os nove FKs e quinze índices novos. Não houve falha preexistente remanescente nas suítes concluídas.

O inventário basal não foi reescrito a partir de dados reais. `database/schema/access-extension.json` acrescenta somente o DDL revisado da extensão, e os verificadores de instalação incorporam essas expectativas de forma explícita. O manifest de instalação foi atualizado para a migration nova. Isso não autoriza reaplicar a cadeia basal em instalações existentes.

## Limites da validação e homologação manual

Não houve publicação remota, alteração de usuários reais, promoção de Lauro, alteração de vínculos reais, importação de dados nem execução da migration no banco fonte. O caso real informado continua pendente no ambiente onde Alex/Lauro/SC-001/SPACE Commerce realmente existem. Não se deve deduzir identidade a partir de IDs iguais.

Os testes HTTP/Livewire validam novas requisições e estado persistido no banco; não executam JavaScript de um navegador real. MySQL 5.7.44 da hospedagem também continua sem ensaio: a migration nova usa tipos/FKs/índices convencionais disponíveis nessa versão, mas isso não certifica a configuração do servidor.

Roteiro em uma cópia de homologação com banco exclusivo:

1. Aplicar somente a migration nova, entrar como administrador já autorizado e conferir os menus. Não copiar `.env` nem apontar homologação para o banco real.
2. Em Usuários, cadastrar um programador de teste, editar nome/e-mail, manter senha em branco, redefinir senha com confirmação e testar desativação/reativação. Conferir bloqueio de autoelevação e de edição de super-admin por admin comum.
3. Em Membros dos projetos, adicionar esse usuário ao projeto de teste com papel Programador e editor. Conferir capacidade de conteúdo/problemas, contador e link de tarefas.
4. Editar a tarefa de teste, selecionar o usuário, salvar, pressionar F5 e reabrir. Conferir responsável em detalhes e Kanban. Testar “Verificar responsável” antes/depois de revogar o vínculo.
5. Entrar como o programador: consultar apenas seus projetos, atualizar a própria tarefa e checklist; confirmar ausência de Usuários/Papéis e permissões e bloqueio de distribuição/arquivamento indevidos. Entrar como leitor e editor para conferir menus e botões.
6. Criar duas organizações de teste, incluir seus membros explicitamente, vincular projetos compatíveis e criar equipes. Conferir que membros da primeira não consultam dados da segunda, inclusive por URLs/IDs conhecidos e filtros.
7. Revogar vínculo de projeto/organização e atualizar a sessão do usuário revogado. Conferir que as atribuições/autorias antigas continuam no histórico, embora o acesso tenha sido removido.
8. Como editor/programador, usar a Central para criar rascunho e submeter revisão; confirmar que apenas o aprovador global autorizado mantém a aprovação anterior. Conferir a matriz para o usuário e projeto selecionados.
9. Em duas abas, tentar salvar formulário antigo após alterar título/responsável/descrição na outra. Conferir aviso sem sobrescrita. Validar responsividade, checkboxes após F5 e ausência de erros JavaScript/Livewire/assets.

## Arquivos e migration

A relação exata de arquivos desta entrega está em [access-files.json](access-files.json). Principais grupos:

- Resources e páginas: Users, ProjectMemberships, Organizations, Teams e PermissionMatrix; ajustes nos Resources existentes de tarefas, projetos e erros.
- Models: Organization, OrganizationMembership, Team, TeamMembership; relacionamentos adicionados a User e Project.
- Services: ProjectAccess, ProjectMembershipService, UserAdministrationService, OrganizationAdministrationService; integração com atribuição/ciclo de tarefas e autorização documental.
- Policies: User, Project, ProjectMembership, Organization, Team e conteúdo de projeto, além dos ajustes nas Policies existentes. Registro explícito em AppServiceProvider.
- Tests: AccessManagementTest, AccessManagementMigrationTest e regressão do comparador DDL.
- Ferramentas: runner MySQL isolado, expectativas de esquema, manifest, verificadores e pacote de atualização.
- **Uma migration incremental:** `2026_10_23_000001_add_organization_access_management.php`. Nenhuma migration executada anteriormente foi renomeada ou alterada. Nenhuma dependência foi adicionada.

## Reprodução local — PowerShell

```powershell
Set-Location 'C:\laragon\www\spacedev'
$accessPhp = 'C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe'
& $accessPhp vendor/phpunit/phpunit/phpunit
& $accessPhp tools/validate-editors-mysql.php --access
& $accessPhp tools/validate-individual-migrations.php --preserve-schema
npm.cmd ci
npm.cmd run build
& $accessPhp tools/build-access-release.php
```

Os dois validadores MySQL criam destinos aleatórios isolados e não modificam o banco fonte. Não executar esses validadores em produção. Os bancos de teste são conservados para inspeção; nenhum DROP automático é feito.

## Publicação futura na HostGator — não executada

O pacote é `dist/spacedev-access.zip`, com `.sha256` e manifest verificável. Inclui o código atual de `app/`, views, assets compilados e **somente a migration incremental nova**. Não inclui `.env`, vendor, dumps, dados, logs, testes, seeds ou a cadeia basal de migrations. É uma atualização complementar para a mesma revisão base e dependências já instaladas; compare o código remoto antes de sobrepor uma instalação personalizada. **Não usar o ZIP antigo de editores ou um pacote de reparação de migrations para esta entrega.**

Antes da publicação: homologar em cópia isolada, fazer backup do banco/arquivos pelo cPanel, confirmar PHP web/CLI 8.3+, mesmo composer.lock/vendor e disponibilidade de CREATE/ALTER/FKs. Não há Composer update/install nem Node obrigatório no servidor. O caminho abaixo segue a estrutura recomendada no projeto; o diretório remoto real não foi inspecionado. Ajuste `APP_DIR` se necessário, conservando o document root em `public/`.

Upload a executar manualmente, com usuário/host reais:

```powershell
scp -P 2222 .\dist\spacedev-access.zip .\dist\spacedev-access.zip.sha256 USUARIO_CPANEL@SERVIDOR:~/
ssh -p 2222 USUARIO_CPANEL@SERVIDOR
```

No Bash/SSH, após as conferências e backup:

```bash
set -eu
PHP83=/opt/cpanel/ea-php83/root/usr/bin/php
APP_DIR="$HOME/spacedev"
RELEASE="$HOME/spacedev-access.zip"
STAGE="$HOME/spacedev-access-stage-$(date +%Y%m%d_%H%M%S)"
BACKUP="$HOME/spacedev-access-backup-$(date +%Y%m%d_%H%M%S).tar.gz"

test -x "$PHP83"
"$PHP83" -v
cd "$HOME"
sha256sum -c spacedev-access.zip.sha256
mkdir "$STAGE"
unzip -q "$RELEASE" -d "$STAGE"
"$PHP83" "$STAGE/tools/check-access-release.php"

cd "$APP_DIR"
test -f artisan
test -f .env
test -f vendor/autoload.php
test ! -f public/hot
"$PHP83" -r 'require "vendor/autoload.php"; echo "Autoload e plataforma OK\n";'
tar -czf "$BACKUP" app resources/views public/build database/migrations

"$PHP83" artisan down
unzip -oq "$RELEASE" -d "$APP_DIR"
"$PHP83" tools/check-access-release.php
"$PHP83" artisan filament:optimize-clear
"$PHP83" artisan config:clear
"$PHP83" artisan route:clear
"$PHP83" artisan view:clear
"$PHP83" artisan migrate --path=database/migrations/2026_10_23_000001_add_organization_access_management.php --force
"$PHP83" artisan filament:assets
"$PHP83" artisan optimize
"$PHP83" artisan filament:optimize
"$PHP83" artisan up
```

Usar `--path` evita executar migrations basais que possam aparecer pendentes em um banco antigo já funcional. Não usar um `migrate --force` genérico nesta atualização. Se houver tabela/coluna parcial ou divergência do histórico, interromper e diagnosticar; não forçar criação, apagar tabelas, editar manualmente o histórico ou tentar rollback destrutivo. DDL MySQL não é integralmente transacional. Se qualquer comando falhar, o roteiro para antes de liberar a aplicação; corrigir a causa ou restaurar o código com procedimento apropriado ao backup, conservando os dados.

Após publicar, fazer smoke de consulta em `/up` e no login/menus autorizados. Testes que criam contas, concedem vínculos, atribuem tarefas ou aprovam conteúdo devem continuar restritos à homologação. Não há seeding de papéis/contas, `key:generate`, atualização de dados reais ou publicação automática neste trabalho.

Referências operacionais do provedor: [SSH HostGator](https://suporte.hostgator.com.br/hc/pt-br/articles/30812824748051-Como-acessar-um-servidor-via-SSH), [caminho PHP HostGator](https://suporte.hostgator.com.br/hc/pt-br/articles/30812660878867-Quais-s%C3%A3o-os-caminhos-para-a-execu%C3%A7%C3%A3o-de-comandos-em-um-Cron-Job).
