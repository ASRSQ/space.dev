# SPACE.dev — revisão consolidada de pré-publicação

Data: 23/10/2026. **Pronta para homologação isolada. Não aprovada para produção. Nenhuma publicação realizada.** Este relatório e `consolidated-installation.md` prevalecem sobre os roteiros históricos de `editor-prepublication-review.md` e `access-management.md`. Não utilizar os ZIPs antigos de editor, acesso ou reparação de migrations nesta atualização.

## 1. Funcionalidades consolidadas

Tarefas e `/admin/problems` compartilham `TaskMarkdown`, views de descrição/preview, checklists persistentes e proteção contra edição obsoleta. A descrição usa CommonMark, remove HTML arbitrário, bloqueia links inseguros e limita aninhamento. Apenas o renderer confiável gera eventos Livewire; a mutação reautoriza no servidor, bloqueia registro/projeto e compara SHA-256 antes de salvar. Texto em bloco de código não vira checklist. Isso reduz XSS e perda de alterações; não equivale a uma auditoria de segurança completa.

Usuários, organizações, equipes, vínculos e matriz de permissões usam Policies e serviços compartilhados. Queries, seletores e Kanban respeitam o projeto/organização. Equipe não concede acesso implícito. Administrador global ativo pode acessar projetos entre organizações; administrador de organização tem escopo limitado. Leitor global limita escrita mesmo quando o vínculo contextual é mais amplo.

Em erros: programador e `developer_editor` ativos podem criar/editar no projeto autorizado; `editor` puro edita conteúdo documental, mas consulta erros/tarefas sem alterá-los. Leitor não recebe checkbox interativo. Conta sem acesso ao projeto não vê registros. Administração de usuários continua restrita aos administradores globais.

Alex administrador pode conceder vínculo `developer_editor` a Lauro, manter o papel global `developer`, atribuir SC-001 e reabrir a edição preservando o responsável. Esse fluxo foi testado com registros sintéticos. **Não foi confirmado para contas reais**: SC-001 e Lauro não foram encontrados no banco local consultado. Nenhuma conta foi promovida ou recriada. Antes de operação futura, verificar e-mail exato confirmado das duas contas, papel/status, slug confirmado do projeto e título/chave da tarefa com seus detalhes; somente depois obter os IDs daquele ambiente. Nome parcial ou ID de outro ambiente não identifica uma pessoa/tarefa com segurança.

## 2. Arquivos incluídos

`dist/spacedev-consolidated.zip` inclui todos os PHP atuais de `app/` e `resources/views/`, assets apontados pelo manifest Vite, uma única migration incremental, este relatório, guia operacional, evidência MySQL e verificador independente. A lista exata de arquivos e respectivos hashes está em `dist/consolidated-release-manifest.json`, também dentro do ZIP. O checksum externo é `dist/spacedev-consolidated.zip.sha256`.

Não inclui `.env`, vendor, dados, dumps, logs, seeds ou migrations basais. O manifest registra hashes de composer.lock/package-lock.json para comparação; não instala dependências. O pacote contém arquivos integrais desses diretórios: personalizações remotas precisam ser comparadas antes de sobreposição. Checksum comprova integridade; transmitir o checksum por canal confiável, pois não é assinatura de autoria.

Nenhum marcador de conflito encontrado em app/views/migrations. `git diff --check` passou. As mudanças dos dois módulos coexistem; a antiga instrução de que somente administradores acessam erros foi substituída pela matriz acima.

## 3. Hospedagem e migration

Testado em PHP 8.3.16, MySQL Laragon 8.4.3, Node 22.15 e Vite 7.3.7. PHP CLI padrão local 8.2 não serve para o vendor instalado; usar PHP 8.3 tanto no web quanto no CLI remoto. Node é necessário para recompilar localmente, não na hospedagem: `npm.cmd run build` passou (59 módulos). CSS do tema Filament e JS/CSS do aplicativo estão compilados; o servidor deve publicar também `filament:assets` a partir do vendor instalado.

A migration `2026_10_23_000001_add_organization_access_management.php` acrescenta cinco tabelas InnoDB e `projects.organization_id` nullable, com FKs RESTRICT. Não altera papéis, contas, tarefas ou atribuições existentes, nem associa organizações automaticamente. `down()` recusa exclusão de histórico. A revisão estática não encontrou sintaxe específica de MySQL 8 (tipos convencionais, índices novos curtos, utf8mb4_unicode_ci); **não houve execução em MySQL 5.7**. Confirmar InnoDB, tipos unsigned bigint compatíveis em users/projects, collation, limites de índice, privilégios e ausência de criação parcial na cópia da hospedagem. DDL MySQL pode deixar estado parcial após falha; não repetir cegamente.

O banco local existente não recebeu migration. Upgrade com dados legados foi verificado automaticamente em SQLite; instalação/integridade funcional com o schema completo em MySQL 8.4.3. Upgrade da cópia real com dados, em MySQL 5.7, permanece bloqueio de produção.

## 4. Testes automáticos

- Regressão PHP: 184 testes, 5.228 assertions, zero falhas, 21 skips de testes MySQL opt-in. Um teste de RoleSeeder foi excluído expressamente para não executar seeds. Nenhum comando de seed foi executado.
- Integração consolidada nativa: **37 testes, 543 assertions, zero falhas/skips**, MySQL 8.4.3, 58 tabelas, migration aplicada somente no destino novo. Ver `consolidated-mysql-validation.json` e XML em `storage/app/private/consolidated-mysql-results.xml`. Runner cria banco novo aleatório local; fixtures sintéticas são revertidas por transação (zero linhas de aplicação ao terminar). Inclui os testes de acesso e dos dois editores, GETs de rotas/Livewire, atribuição e reabertura, bloqueio de leitor, escopo organizacional, revogação, XSS, CRLF, versões obsoletas e exclusão de escritor concorrente pelo MySQL.
- Snapshot antes/depois de **todas as tabelas base existentes**, incluindo linhas e SHOW CREATE TABLE, comprova que o banco fonte permaneceu inalterado durante a integração. Não foram usados IDs reais como fixtures. Nenhuma migration, seed ou gravação foi dirigida ao banco fonte. Bancos novos de validação foram mantidos para inspeção, sem DROP.
- Nova cobertura integrada: Lauro contextual `developer_editor` cria/edita erro e persiste checkbox após nova requisição; ao passar a editor documental, GET de edição e mutação de componente previamente aberto são negados. Papel global permanece developer.
- Rotas administrativas foram listadas sem erro. Menus/ações e negação de administração são cobertos nos componentes/Policies; interação visual e clique real no navegador continuam pendentes.
- Build Vite passou. ZIP com 132 arquivos foi extraído e aprovado pelo checker independente. Alteração intencional do manifest Vite na cópia extraída foi rejeitada (exit 1), conforme esperado. O ZIP original permaneceu intacto; instalação e rollback na hospedagem não foram executados.

Comandos locais reproduzíveis, sem seed:

```powershell
& 'C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe' vendor/phpunit/phpunit/phpunit --filter '/^(?!.*role_seeder).*$/' --log-junit storage/app/private/consolidated-regression.xml
& 'C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe' tools/validate-editors-mysql.php --consolidated
npm.cmd run build
& 'C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe' tools/build-consolidated-release.php
```

## 5. Riscos

MySQL 5.7 e hospedagem não executados; DDL sem rollback integral; deploy precisa de manutenção para impedir escrita durante troca de código. Conflitos de descrição/formulário são detectados e solicitam nova conferência, sem mesclar automaticamente alterações. Revogação é reavaliada no servidor na próxima operação; uma alteração já em execução deve ser incluída na homologação concorrente. Dependências e customizações remotas divergentes exigem reconciliação antes de instalar. Restaurar um dump antigo após novas escritas perderia dados: rollback padrão conserva schema e registros e restaura código completo, sem apagar tabelas.

## 6. Pendências críticas de homologação

- Executar upgrade em cópia anonimizada da base real na mesma versão/configuração MySQL 5.7 da hospedagem; comparar todas as linhas anteriores, FKs, atribuições e organizações, testar aplicação e ensaiar rollback de arquivos. Esta etapa não foi feita aqui.
- Confirmar identidades reais por e-mail/projeto e elegibilidade de Lauro; validar SC-001 sem promover a superadmin. Qualquer concessão/atribuição real depende de autorização posterior.
- Navegador: criar/editar/ver tarefas e erros, toolbar/preview, checkbox após F5, responsável selecionado, erros de conflito em duas abas, teclado/mobile, ausência de erros JS/404 de assets, menus para cada papel e isolamento entre organizações.
- Duas sessões: editar texto simultaneamente, checkbox versus formulário, revogar vínculo/arquivar projeto com tela aberta; confirmar mensagem de conflito e ausência de perda de dados.
- Hospedagem: PHP web/CLI e extensões, vendor/lock idênticos, document root real em public/, permissões storage/bootstrap/cache, rota/cache Filament, backup restaurável e espaço para cópia completa. Testar rollback operacional em homologação antes de autorizar produção.

## 7–8. Publicação e rollback

Procedimentos completos em `consolidated-installation.md`. Não foram executados. **Estado final: disponível para homologação isolada; publicação bloqueada até concluir os itens críticos acima e obter autorização explícita.**
