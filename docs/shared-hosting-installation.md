# Instalação do SPACE.dev em hospedagem compartilhada

## Escopo e compatibilidade

Este procedimento instala um **banco vazio**, sem importar `spacedev_test`. A cadeia contém uma base de 43 tabelas de domínio, infraestrutura Laravel e duas migrations incrementais (Central de Documentação e prazos). Resultado: 53 tabelas, incluindo `migrations`, e 6 triggers. O arquivo `database/schema/space-baseline.json` é parte obrigatória do pacote; contém somente DDL versionado, sem usuários, tokens ou dados de desenvolvimento.

Use PHP **8.3 ou superior compatível com composer.lock**, tanto na web quanto no terminal. As dependências instaladas exigem PHP 8.3, embora o composer.json do projeto aceite 8.2. Habilite Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, **pdo_mysql**, Session, Tokenizer, XML e **Intl** (Filament). Confira as exigências completas do lock com `composer check-platform-reqs --no-dev`; não use `--ignore-platform-reqs`. A base de requisitos do framework está na [documentação Laravel 12](https://laravel.com/docs/12.x/deployment#server-requirements).

MySQL: mínimo aceito pela base **8.0.16**, com suporte a índices funcionais; validação realizada em **8.4.3**. Não há CHECKs explícitos no esquema inventariado: as restrições existentes usam FKs, índices, tipos e triggers. Prefira uma versão MySQL suportada pelo provedor. InnoDB e utf8mb4 são obrigatórios. **MariaDB não é compatível com esta cadeia**, apesar da conexão genérica disponível em config/database.php: os índices funcionais da Central usam sintaxe MySQL. SQLite também não instala a base de produção. Outras versões MySQL não foram executadas nesta auditoria.

O usuário do banco precisa de SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP e TRIGGER **somente no banco contratado**. Crie banco e usuário pelo painel da hospedagem; a aplicação não executa CREATE DATABASE, GRANT, SET GLOBAL, nem fixa DEFINER. CREATE TRIGGER usa a conta instaladora como definidor: conserve essa conta ou solicite ao provedor a transferência antes de removê-la.

Confirme com o provedor que permite CREATE TRIGGER para o usuário contratado. Com binary log habilitado, certas configurações do servidor podem exigir privilégio global para criar triggers; o provedor deve disponibilizar uma configuração que permita sua criação com privilégios do banco. A aplicação não modifica essa configuração e não deve receber SUPER. Se o plano não permitir, ele não atende ao esquema atual. Veja [CREATE TRIGGER](https://dev.mysql.com/doc/refman/8.4/en/create-trigger.html) e [binary logging de stored programs](https://dev.mysql.com/doc/refman/8.4/en/stored-programs-logging.html).

## Preparação dos arquivos

1. Use o código revisado e seus arquivos de lock. Não envie `.env` local, `.git`, bancos, logs, `node_modules` ou caches gerados no computador local.
2. Aponte o document root do domínio para `/home/CONTA/spacedev/public`. O projeto e `.env` devem permanecer fora da raiz pública. Se o painel fixa `public_html`, solicite ajuste do document root; não exponha a raiz do projeto. Ative HTTPS e o rewrite do Apache (`public/.htaccess`).
3. Se houver Composer no servidor, execute na raiz, com o PHP 8.3 correto:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
```

Sem Composer no servidor, prepare `vendor` em ambiente Linux compatível com o PHP/extensões da hospedagem usando o mesmo lock e envie o pacote. Os scripts Composer publicam recursos Filament; confira também `public/js` e `public/css` gerados. Não use `composer update` durante a implantação.

Prepare os assets localmente/CI com Node compatível com o lock do Vite 7:

```sh
npm ci
npm run build
```

Envie `public/build` e seu manifest. Não é necessário manter Node ou `npm run dev` na hospedagem. Garanta escrita pelo usuário PHP em `storage` e `bootstrap/cache`, sem permissões 777. Preserve os diretórios internos de `storage`.

## Configuração do ambiente

Crie `.env` de produção no servidor com acesso restrito ao proprietário. Exemplo com valores que devem ser substituídos:

```dotenv
APP_NAME="SPACE.dev"
APP_ENV=production
APP_DEBUG=false
APP_KEY=
APP_URL=https://SEU-DOMINIO
LOG_CHANNEL=stack
LOG_LEVEL=warning
DB_CONNECTION=mysql
DB_HOST=HOST-INFORMADO-PELO-PROVEDOR
DB_PORT=3306
DB_DATABASE=BANCO-VAZIO-DA-HOSPEDAGEM
DB_USERNAME=USUARIO-DO-BANCO
DB_PASSWORD="SENHA-GERADA-NO-PAINEL"
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
```

Não configure DB_URL concorrente com esses parâmetros. Use credenciais exclusivas desse banco; aplique TLS conforme as instruções do provedor (`MYSQL_ATTR_SSL_CA`, se necessário). Configure SMTP real conforme o serviço contratado. O driver `log` não entrega mensagens.

Execute antes de cachear configurações:

```sh
php artisan config:clear
php artisan key:generate --force
```

Gere APP_KEY apenas na primeira instalação. Faça backup seguro dela: os modelos de GitHub e IA cifram segredos usando essa chave; trocá-la posteriormente impede a leitura desses valores.

## Banco e primeiro administrador

Confirme no painel o nome completo (inclusive prefixo da conta) e que o banco está vazio. Não use o banco local nem um banco de outra aplicação.

```sh
php artisan migrate --force
php artisan db:seed --class=RoleSeeder --force
php artisan space:create-admin
php artisan migrate:status
```

O comando `space:create-admin` pede nome, e-mail, senha oculta e confirmação. Exige pelo menos 12 caracteres, maiúsculas/minúsculas, número e símbolo; grava hash pelo cast existente do modelo. Não aceita senha por argumento nem execução não interativa. Recusa criação quando já há um administrador ativo. O DatabaseSeeder também foi corrigido para criar somente papéis, sem usuário de teste.

Sem terminal interativo, peça ao provedor acesso SSH/terminal do painel para executar o comando. Não crie um instalador público ou senha fixa para contornar isso.

A migration base recusa tabelas de domínio preexistentes, evitando adotar uma estrutura desconhecida. **Não execute esta nova base em instalações existentes**, inclusive `spacedev_test`: o histórico local só contém as duas migrations incrementais, portanto a nova base aparecerá pendente. A adoção da base em um banco existente exige procedimento separado, com comparação e backup, fora deste roteiro de instalação limpa.

DDL MySQL não é integralmente transacional. Se ocorrer falha por falta de permissão ou interrupção, pare e inspecione o banco; não marque migrations manualmente como concluídas. Corrija o requisito e recrie pelo painel apenas o banco novo e descartável antes de repetir. Rollback da base está bloqueado para proteger dados; depois que houver dados, use restauração de backup e migrations corretivas. O rollback da Central também recusa perda de rascunhos/seções.

## Publicação e tarefas em segundo plano

```sh
php artisan storage:link
php artisan optimize
```

Use storage:link somente quando houver arquivos públicos e suporte a symlinks no plano. Não publique discos privados. Em cron, configure o PHP e caminho absolutos do provedor:

```cron
* * * * * /CAMINHO/php /home/CONTA/spacedev/artisan schedule:run >> /home/CONTA/spacedev/storage/logs/scheduler.log 2>&1
```

Se não puder manter worker por supervisor, processe a fila por cron a cada minuto, evitando sobreposição pelo mecanismo do painel:

```sh
/CAMINHO/php /home/CONTA/spacedev/artisan queue:work --stop-when-empty --max-time=50 --tries=3
```

Use o mesmo PHP 8.3, usuário e diretório da aplicação. Defina timeout/retry_after coerentes com os jobs e limites do provedor. Como alternativa inicial, `QUEUE_CONNECTION=sync` executa jobs durante a requisição e fica sujeito ao tempo limite HTTP.

Abra `/admin/login`, entre com o administrador criado e confira projeto, tarefa com prazo e publicação na Central. Verifique `/up`, logs, sessão persistente e proteção de `.env`/arquivos privados (não acessíveis por HTTP). Configure credenciais reais de GitHub/IA pelo fluxo da aplicação e valide as APIs separadamente; a auditoria testou armazenamento e relacionamentos, sem chamar serviços externos.

Mantenha backups do banco, arquivos enviados e APP_KEY fora da raiz pública. Antes de atualizações, faça backup e consulte `migrate:status`; nunca execute `migrate:fresh`, `migrate:refresh` ou rollback da base em produção. Hospedagem que não ofereça MySQL compatível, triggers e terminal para inicialização não atende a esta instalação.

## Reprodução da validação local

Na estação de desenvolvimento, com `.env` apontando para `spacedev_test` e PHP 8.3:

```sh
php tools/schema-audit.php
php tools/validate-clean-install.php
php vendor/bin/phpunit
```

O primeiro script só consulta metadados e histórico de migrations. O segundo cria um banco aleatório `spacedev_clean_validation_*`, executa migrations, compara DDL e triggers e testa os fluxos em transação desfeita. Ele requer permissão local de CREATE DATABASE **apenas para o teste**, não para a instalação na hospedagem. Não execute esses scripts de auditoria no servidor de produção. Bancos de validação são mantidos para inspeção e podem ser removidos depois pelo administrador, conferindo seus nomes exatos. `tools/prepare-schema-baseline.php` é uma ferramenta offline de preparação; não é etapa da implantação e não deve regenerar uma base já publicada.
