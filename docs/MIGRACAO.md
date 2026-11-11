# Migração do aplicativo PHP original

## O que é importado

| Origem | Destino |
| --- | --- |
| `usuarios` | Contas nativas WordPress e mapa de IDs |
| `veiculos` | Veículos ligados ao usuário WordPress proprietário |
| `veiculos_compartilhados` | Acesso por veículo, mantendo aceitos e pendentes |
| `abastecimentos` | Data, quilometragem, combustível, preço, litros, valor pago e autor |

O ZIP de código do aplicativo original não inclui os registros do banco. Seu
`database.sql` define as tabelas, mas não traz comandos INSERT. Para migrar dados
reais, exporte o banco que a instalação antiga utiliza.

## Se o app está em outro banco

1. No phpMyAdmin da hospedagem antiga, selecione o banco usado pelo app.
2. Exporte as tabelas `usuarios`, `veiculos`, `veiculos_compartilhados` e
   `abastecimentos` em SQL, incluindo **estrutura e dados**. Use INSERT padrão,
   com nomes de colunas e valores literais. Não é necessário incluir outras tabelas.
3. Instale/atualize o plugin no WordPress.
4. Abra **Portal de Serviços → Importar app antigo**.
5. Selecione **Backup SQL ou JSON** e envie o arquivo, até 10 MB e 50 mil registros.
6. Deixe o prefixo vazio se os nomes forem os nomes originais acima. Se usava
   `antigo_usuarios`, por exemplo, informe `antigo_`.
7. Mantenha o identificador `app-original` para essa origem.
8. Clique em **Conferir dados antes de importar**, leia o resumo e inicie.

Se usar JSON, ele deve ter as quatro chaves acima, cada uma com uma lista de
objetos com os nomes de colunas do banco antigo. Também é aceito o objeto `tables`
envolvendo essas quatro chaves. Arquivos ZIP/GZip não são aceitos; envie SQL/JSON
descompactado. No SQL, comandos de estrutura, exclusão ou atualização não são
executados; o importador apenas interpreta os valores dos INSERTs das tabelas aceitas.

## Se as tabelas estão no mesmo banco do WordPress

Selecione **Tabelas no banco deste WordPress**, informe o prefixo das tabelas
antigas se houver e confira o resumo. A leitura é feita pela conexão já
configurada no WordPress. Não é necessário copiar senhas de banco para o plugin.
As tabelas de origem são somente lidas e permanecem intactas.

## Contas e senhas

| Situação | Comportamento |
| --- | --- |
| E-mail ainda não existe no WordPress | Cria conta WordPress com nome e e-mail antigos |
| E-mail já existe | Vincula ao ID existente, mantendo nome, senha, papel e bloqueios |
| Administrador no app antigo | Nova conta recebe o papel Cliente do portal |
| Hash bcrypt compatível | A mesma senha antiga funciona no WordPress |
| Primeiro login em WordPress atual | O WordPress atualiza o hash ao seu formato nativo |
| Hash ausente ou incompatível | Nova conta recebe senha aleatória e precisa recuperar o acesso |

Não são enviados e-mails em massa pelo importador. A pessoa pode usar a opção
**Esqueci minha senha** do portal; configure o SMTP do WordPress para essa função.
Se uma conta foi vinculada pelo e-mail, vale a senha que ela já usava no WordPress.
Hashes existentes não são expostos na tela ou na resposta de processamento.

## Dados históricos e conferências

O app antigo não registrava se o tanque estava completo. A preparação permite
marcar todos os registros antigos como completos para manter essa premissa, ou
importá-los como parciais. Ajuste os registros conhecidos depois da migração.

Se um backup muito antigo não tiver `valor_pago`, o padrão é interromper a
conferência. Há uma opção explícita para calcular preço de tabela × litros
somente nesses casos. Valores existentes, inclusive zero, são mantidos.
O resumo informa quando essa conversão será feita.

Abastecimentos com a mesma quilometragem são preservados, inclusive dois no
mesmo dia. Registros futuros existentes também são preservados e sinalizados.
Novos lançamentos pelo app continuam tendo validação de datas e sequência.
Datas de criação sem fuso são interpretadas no fuso configurado no WordPress e
convertidas em UTC; as datas dos abastecimentos permanecem iguais às da origem.

Compartilhamentos aceitos liberam apenas o veículo específico. Pendentes são
guardados como pendentes, sem liberar acesso. O proprietário pode gerenciá-los
no veículo; adicionar novamente o e-mail no compartilhamento concede o acesso.

E-mails inválidos, IDs duplicados ou relações sem usuário/veículo correspondente
são detectados na preparação, antes de gravar. Corrija o backup nesses casos.

## Retomar e repetir

O processamento usa etapas pequenas e salva um mapa entre IDs antigos e novos.
Se a página fechar ou a conexão cair, volte à mesma tela e continue. A preparação
expira depois de uma hora sem processamento; nesse caso, reenvie o mesmo backup
com **o mesmo identificador de origem**.

Um registro já migrado não é duplicado nem sobrescrito. Registros excluídos do
novo app não são recriados ao repetir a importação; os respectivos vínculos
funcionam como referência de que já foram processados. Não altere o identificador
para forçar uma repetição. Uma origem realmente diferente precisa de um identificador próprio.

Ao concluir, o conteúdo temporário do backup é removido da preparação. Permanece
um relatório de contagens por 24 horas e o mapa de IDs necessário à repetição.
O arquivo enviado nunca é publicado na pasta de mídia do WordPress.

Finalize os lançamentos na versão antiga antes da migração definitiva. Confira
os totais e teste uma conta importada. O importador não apaga o banco antigo.
O mapa de IDs, dados e contas são preservados nas atualizações do plugin.
