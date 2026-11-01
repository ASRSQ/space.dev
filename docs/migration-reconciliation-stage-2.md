# Etapa 2 — interrompida nas verificações prévias

Data: 28/10/2026. **Nenhuma alteração foi aplicada ao banco original.** A execução foi interrompida conforme a instrução do usuário de parar diante de qualquer divergência, antes de tentar recuperação.

## Verificações aprovadas

- Conexão local efetiva: `spacedev_test`, servidor `Alex`, porta 3306, MySQL 8.4.3, host de loopback, sem URL alternativa e sem cache de configuração.
- Permanecem **46 tabelas**, **dois registros em migrations** e **97 registros de negócio**.
- Todos os hashes e contagens das linhas originais coincidem com a etapa 1, inclusive usuários, permissões, configurações e histórico. Todas as listas/ordens de colunas coincidem.
- Os SHA-256 dos 96 arquivos de migrations e dos demais artefatos com hashes no ensaio coincidem: **98 caminhos únicos verificados**. Isso verifica os arquivos documentados pelo ensaio, não constitui fingerprint de todo o repositório.
- Backup durável existe, tem 95.923 bytes e checksum SHA-256 `e4cd20a4824184d1915c9b587e6322791a6539b866a38d38fee03641553ea270` correspondente ao registrado. O relatório durável comprova a restauração previamente realizada desse backup e coincide com a referência local. Não foi feita nova restauração nesta etapa, cuja autorização se limita ao banco original.
- `php83 tools/schema-audit.php --verify` passou: o DDL, os seis triggers e o histórico do banco permanecem iguais ao inventário original versionado.

## Divergência que interrompeu a aplicação

O JSON `docs/migration-reconciliation-rehearsal.json`, usado como referência da etapa 1, contém caracteres acentuados recodificados incorretamente em textos de DDL e corpos dos triggers. A regravação desse arquivo via PowerShell na etapa 1 introduziu esse problema de representação. A cópia durável desse relatório contém a mesma representação.

A comparação direta falhou em **43 descrições de tabelas e nos seis corpos de triggers**. A investigação somente de leitura demonstrou que todas essas diferenças desaparecem exatamente ao desfazer uma conversão UTF-8/Windows-1252 apenas para comparação diagnóstica. Os 46 DDLs atuais correspondem ao inventário original não afetado. Não há evidência de alteração estrutural efetiva no banco, nem divergência nos hashes dos registros.

Nenhuma referência foi regravada/corrigida e nenhuma normalização adicional foi aceita para liberar a aplicação. O preflight continua com resultado **STOPPED**. O próximo procedimento precisa primeiro corrigir a representação da evidência, preservando a versão anterior e conferindo-a novamente com o inventário original e o backup, antes de uma nova tentativa de aplicação.

## Identidades solicitadas

As consultas em users por nomes contendo `Alex` ou `Lauro` não retornaram contas. `Alex` é o hostname do servidor, o que não identifica uma conta de aplicação.

Assim, não foi possível confirmar perfil administrativo de Alex nem permissões de programador/editor de Lauro. É necessário identificar essas contas por IDs ou e-mails exatos. Não foram criadas, renomeadas ou promovidas contas, nem alterados vínculos, senhas ou identidades.

## Situação da aplicação e validações finais

- Adoção das 89 migrations: **não executada**.
- Infraestrutura, organizações e normalização dos índices: **não executadas no original**.
- Estado final original: **46 tabelas e duas migrations registradas**, sem mudança dos 97 registros de negócio.
- Módulo de organizações: ainda não instalado no original; não foi feito teste HTTP pós-aplicação, pois não houve aplicação.
- Testes de integração/regressão pós-aplicação: não executados nesta etapa interrompida. Permanecem as evidências do ensaio, sem apresentá-las como resultados do original.
- Nenhum seeder, comando destrutivo, recuperação automática, deploy ou alteração de banco de outro projeto foi executado. HostGator e produção não foram acessados.
- Publicação continua dependendo de validação real em MySQL 5.7.44 e das condições de InnoDB, índices, TRIGGER, binary log e definers já descritas na etapa 1.

Evidência completa: `docs/migration-reconciliation-stage-2-preflight.json`. Ferramenta somente de leitura: `tools/preflight-migration-reconciliation-stage-2.php`.
