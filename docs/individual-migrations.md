# Migrations individuais do SPACE.dev

Esta cadeia substitui a execução do JSON por código Laravel revisável. A fonte é
`spacedev_test`, MySQL 8.4.3. O banco de referência nunca é destino de migrations.

## Organização

- `0000_01_01_*`: 43 tabelas base, incluindo `users`, com Blueprint.
- `0000_01_02_*`: 40 migrations aplicando 79 FKs após todas as tabelas e índices.
- `0000_01_03_*`: seis migrations de triggers, com seus corpos SQL originais.
- `0001_*`: três migrations existentes para sete tabelas de infraestrutura.
- `2026_10_18_000003_*`: documentação, duas tabelas de drafts, quatro FKs,
  seções e unicidade condicional.
- `2026_10_19_000001_*`: prazos de tarefas.

São 89 migrations novas e 94 arquivos ativos no total. O resultado esperado é
53 tabelas (45 de domínio, sete de infraestrutura e `migrations`), 83 FKs,
172 índices incluindo primários (159 na referência e 13 de infraestrutura),
seis triggers e 375 colunas nas 46 tabelas comparadas (373 originais mais duas
colunas geradas). `document_tags` mantém PK composta e não recebe `id`.

As referências compostas entre entidades e suas versões são mantidas, inclusive
os índices únicos de pares e suas regras ON UPDATE/ON DELETE. Não há desativação
de verificações de FKs. Os comentários de índices exigem SQL explícito porque o
grammar MySQL do Blueprint não os emite. O formato DYNAMIC é explicitado.

O baseline JSON permanece como referência, sem ser carregado pelas migrations.
A migration consolidada original está em `migration-refactor-original/`, junto
ao estado inicial do Git e ao patch das alterações que já existiam.

## Compatibilidade

MySQL 8.4.3 é a versão local de validação. MySQL 5.7.44 é alvo **provisório,
não aprovado sem execução real nessa versão**. MySQL 5.7 está fora do suporte
regular e deve ser tratado como compatibilidade transitória. MariaDB e SQLite
não são destinos desta cadeia de produção. Use PHP 8.3 compatível com o lock.

Os dois índices funcionais foram substituídos por colunas BIGINT UNSIGNED
VIRTUAL `pending_document_id` e `pending_technical_context_id`, com CASE idêntico
e índices únicos com os nomes originais. Apenas status pending gera um ID;
os outros estados geram NULL. A alteração física é intencional e é a única
diferença de colunas do domínio. `active_user_project_key` continua virtual.

Todas as tabelas de domínio declaram utf8mb4_unicode_ci. Configure também a
conexão e o banco novo com essa collation; não importe utf8mb4_0900_ai_ci do
contexto original dos triggers. O DEFINER é o usuário instalador, nunca root
fixo. É necessário privilégio CREATE TRIGGER, sujeito às políticas do provedor.

No MySQL 5.7, verificar InnoDB DYNAMIC, innodb_large_prefix, formato Barracuda,
tamanho de página e os limites completos dos índices. Nunca truncar índices
únicos ou colunas para contornar limites. Também testar cascatas de IDs usados
em expressões geradas, concorrência e SQL modes. Qualquer diferença de
integridade bloqueia a aprovação; não basta a instalação terminar sem erro.

## Instalação limpa

Somente depois de revisar os resultados para a versão de destino:

1. Criar um banco vazio e exclusivo. Confirmar nome/host e restaurabilidade do backup.
2. Instalar dependências pelo lock e configurar a conexão desse banco novo.
3. Conferir o pacote completo, incluindo `app/Support/DomainMigrationPlatform.php`.
4. Executar `php artisan migrate --force`.
5. Executar `php artisan db:seed --class=RoleSeeder --force` e o procedimento
   administrativo de primeiro usuário já documentado. Não importar dados locais.

Não usar o pacote antigo `dist/spacedev-migration-repair.zip` com esta cadeia.
Seu manifest descreve a implementação consolidada anterior. O verificador antigo
deve rejeitar esta combinação; não regenere o ZIP anterior para ocultar diferenças.

## Instalações existentes: procedimento separado

Não executar a cadeia nova diretamente em banco existente ou parcial da HostGator.
O banco oficial possui somente as duas migrations incrementais no histórico;
as tabelas base existentes não tornam as novas migrations executadas.

1. Fazer backup consistente, testar restauração e obter inventário/DDL/histórico
   da instalação específica em modo somente leitura.
2. Restaurar uma cópia em ambiente isolado, com dados protegidos.
3. Comparar todas as tabelas, colunas, índices, FKs, triggers e históricos com o
   inventário oficial; não se limitar a nomes de objetos. Classificar estado
   consolidado completo, infraestrutura apenas, domínio parcial ou divergente.
4. Para base completa, validar numa cópia a equivalência com cada migration nova
   e preparar um plano de adoção do histórico com evidências e revisão própria.
   Este release não altera nem registra artificialmente linhas em `migrations`.
5. Migrar os índices condicionais existentes por uma migration futura específica,
   com verificação de duplicidades e equivalência antes de remover o índice antigo.
   Editar o arquivo já executado não atualiza o banco existente.
6. Para estado parcial, preparar reparo explícito ou instalar em banco novo e
   planejar transferência validada de dados. Não usar IF NOT EXISTS para aceitar
   estruturas desconhecidas. Só publicar após ensaio e revisão desse plano.

DDL MySQL confirma implicitamente e pode deixar estado parcial. Não repetir
automaticamente uma migration que falhou. A proteção de rollback do domínio
permanece; descarte apenas bancos de teste identificados, após inspeção.

## Validação e revisão antes do commit

Usar o PHP 8.3 do Laragon. Com a conexão local apontando para spacedev_test,
`php tools/validate-individual-migrations.php` cria um banco novo aleatório com
prefixo `spacedev_clean_validation_`. Não altera o banco fonte. A opção
`--infrastructure-first` testa infraestrutura aplicada antes da cadeia de domínio.
Os bancos temporários são retidos para inspeção, não excluídos automaticamente.

O verificador compara DDL vivo com o inventário, testa a cadeia inteira e aceita
somente as duas substituições documentadas de índices funcionais e DYNAMIC
explícito. Verifica FKs, triggers, unicidade, referências compostas e exclusões.
Os relatórios novos usam nomes `docs/individual-*`, preservando os relatórios
preexistentes. `php vendor/bin/phpunit` executa a suíte padrão em SQLite em
memória; os testes MySQL antigos de alvo fixo podem ser pulados explicitamente.

Antes do commit: revisar `git diff`, os arquivos não rastreados e os relatórios;
conferir o patch inicial; executar lint/Pint e testes; confirmar fonte inalterada
com `php tools/schema-audit.php --verify`. Não declarar MySQL 5.7 aprovado com
base em teste no 8.4. Não fazer push/deploy como parte da revisão local.

O gerador `tools/generate-individual-migrations.php` consulta somente metadados,
verifica o inventário e recusa sobrescrita por padrão. `--replace-generated`
é apenas para desenvolvimento desta refatoração, nunca para atualizar produção.

## Resultados locais registrados

Os dois cenários (vazio e infraestrutura primeiro) passaram em MySQL 8.4.3,
com zero divergências após a adaptação documentada: 19 checks funcionais e
37 checks adicionais em cada cenário, incluindo as seis triggers, os quatro
pares de referência de versão, cascatas das três expressões geradas, exclusões
e down/up das duas incrementais. O schema foi comparado novamente após down/up.
Nenhuma linha de aplicação permaneceu nos bancos de validação.

O maior índice declarado tem 1020 bytes na instalação completa; a instância
testada usa páginas de 16384 bytes. Isso cabe no limite de 3072 bytes do formato
DYNAMIC, mas excede 767 bytes e exige verificar as opções do destino 5.7.

A suíte padrão registrou 133 testes, 4940 assertions e oito testes MySQL de alvo
fixo pulados; não são tratados como aprovações. Os novos checks MySQL usam os
bancos descartáveis desta refatoração. Concorrência entre sessões independentes
não foi simulada; a unicidade foi exercitada em INSERT, transição de status e
cascatas, com verificação dos códigos de erro do servidor.

Para conferir os arquivos do release, executar
`php tools/individual-installation-manifest.php`. A opção `--write` deve ser
usada somente após revisão intencional das mudanças; ela não certifica o banco.
O manifest novo é separado do manifest/pacote antigo, que foi preservado.
