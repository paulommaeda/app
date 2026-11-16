# Portal de Serviços

Plugin WordPress de Paulo Maeda para um portal de serviços com conta única.
Reúne Meu Combustível e Meu Financeiro: veículos, abastecimentos, gastos, contas, recebimentos, cartões e faturas.

**[Baixar o plugin instalável](https://raw.githubusercontent.com/paulommaeda/app/main/releases/portal-de-servicos-1.18.0.zip)**

## Financeiro compartilhado — 1.18.0

Em **Meu Financeiro → Criar compartilhado**, crie um grupo vazio ou compartilhe o
financeiro atual, preservando os dados e recebendo um novo financeiro pessoal vazio.
Em **Compartilhamento e atividade**, convide pessoas por e-mail com acesso de consulta,
lançamentos ou administração. Cada pessoa usa seu próprio usuário WordPress e Telegram.

No bot, **/financeiros** lista os destinos e **/financeiro ID** seleciona onde registrar.
A autoria fica no histórico; remover um participante preserva os lançamentos e retira
seu acesso. Veja o [guia do financeiro compartilhado](docs/FINANCEIRO-COMPARTILHADO.md).

## Editar e excluir contas a pagar — 1.15.0

Em **Meu Financeiro → Contas a pagar**, cada conta pendente, paga ou cancelada
oferece **Editar** e **Excluir**. Corrija os dados previstos e, nas contas pagas,
o valor, a data e a conta/cartão do pagamento realizado. O mesmo gasto é atualizado,
sem duplicação, e os saldos/faturas são recalculados.

Nas recorrências, escolha **Somente esta conta** ou **Esta e as próximas**.
As próximas alterações preservam pagamentos e ocorrências anteriores. Excluir uma
conta paga remove também o gasto correspondente; a confirmação explica o efeito.
Contas excluídas não reaparecem no cron. Faturas automáticas continuam gerenciadas
por **Conferir / pagar fatura**, pelas compras e pagamentos que compõem seu valor.
Veja [o guia de contas e recorrências](docs/PLANEJAMENTO.md).

## Investimentos e reservas — 1.16.0

Em **Meu Financeiro → Contas**, escolha o tipo **Investimento / reserva** para
separar esse dinheiro do disponível no mês. O fluxo exibe **Disponível para uso
hoje**, **Investimentos e reservas hoje**, **Resgatado da reserva** e **Aportado
à reserva**. O saldo reservado e seus movimentos não entram no fluxo de uso diário.

Use **Resgatar / transferir** para enviar somente o necessário a uma conta de uso
diário: R$ 800 transferidos de uma reserva de R$ 10 mil disponibilizam apenas
R$ 800, mantendo R$ 9.200 reservados. Aportes fazem o caminho inverso, sem criar
receitas ou gastos. A finalidade também pode ser definida nos tipos personalizados.
Contas existentes podem mudar de tipo sem perder saldos ou extratos.
Veja [o guia de investimentos e reservas](docs/INVESTIMENTOS.md).

## Baixas pelo Telegram — 1.17.0

Dê baixa em contas e recebimentos previstos usando o mesmo bot e vínculo do portal:
**“Dê baixa na luz prevista de novembro: paguei 195 reais ontem no Pix pela C6 PJ”**
ou **“Recebi o salário previsto de novembro, 2 mil reais hoje na C6 PJ”**.

O bot localiza a previsão e atualiza o lançamento original, com valor/data reais,
sem duplicar gastos ou recebimentos. Nas recorrências, baixa somente a ocorrência
escolhida. **/pendentes** permite selecionar a previsão; **/baixar** mostra a ajuda.
Também aceita reembolsos de pessoas e pagamentos parciais de fatura. Mantém a
confirmação configurada e pede escolha quando houver várias previsões semelhantes.
Veja [o guia de baixas pelo Telegram](docs/BAIXAS-TELEGRAM.md).

## Instalação

1. Baixe o ZIP instalável acima.
2. No WordPress, acesse **Plugins → Adicionar plugin → Enviar plugin**.
3. Instale e ative. A página **Portal de Serviços** será criada automaticamente.
4. Abra **Portal de Serviços** no painel para configurar nome, cadastro e serviços.

Requisitos: WordPress 6.6+, PHP 8.0+ e banco MySQL/MariaDB do WordPress.
O shortcode principal é `[portal_servicos]`; `[portal_combustivel]` abre o serviço diretamente.

## Importar o aplicativo antigo

Acesse **Portal de Serviços → Importar app antigo**. Você pode enviar o backup
**SQL/JSON com os dados** ou ler as tabelas antigas que estiverem no mesmo banco
do WordPress. Confira o resumo e clique em **Iniciar importação**.

Os usuários antigos são criados em `wp_users`. Se o e-mail já existir, a conta
é vinculada sem alterar senha, nome ou permissões. Senhas bcrypt compatíveis
do app original continuam funcionando. Novas contas recebem o papel restrito
**Cliente do portal**, inclusive os antigos administradores do app.

Veículos, autores de abastecimentos e compartilhamentos são ligados aos novos
IDs. Repetir o mesmo banco com o mesmo identificador de origem não duplica os
registros nem sobrescreve edições feitas no app novo. Os dados antigos não são alterados.

O arquivo `database.sql` de estrutura, sozinho, não contém os registros dos usuários.
Veja [o procedimento de migração](docs/MIGRACAO.md).

## Atualizações pelo WordPress

O plugin consulta o arquivo público [`update.json`](update.json) deste repositório.
Quando uma versão nova é publicada, ela aparece em **Plugins** e **Painel →
Atualizações**. É possível ativar a atualização automática pelo controle nativo
do WordPress. O pacote baixado tem sua integridade SHA-256 conferida antes da instalação.

Se já instalou a versão 1.0.0, envie o ZIP 1.14.0 uma vez pelo WordPress e escolha
substituir a instalação atual. As próximas versões poderão ser atualizadas pelo painel.

Para publicar uma versão nova:

1. Aumente a versão em `portal-de-servicos.php` e `readme.txt`.
2. Atualize o changelog e execute `python3 tools/build_release.py`.
3. Publique o código, `update.json` e o novo ZIP em `releases/` na branch `main`.
4. Não substitua os arquivos ZIP de versões anteriores.

O script não contém tokens ou credenciais. A atualização não depende da API
autenticada do GitHub nem de serviços de assinatura pagos.

## Telegram por áudio e texto

Em **Portal de Serviços → Telegram**, escolha **Gemini (Google)**, informe a chave
criada no [Google AI Studio](https://aistudio.google.com/apikey), salve e use
**Testar reconhecimento**. O Gemini oferece faixa gratuita sujeita ao modelo
e às cotas do projeto. Para usar sem cobrança, mantenha o projeto no nível
gratuito, sem habilitar faturamento. Na faixa gratuita, o Google pode usar o
conteúdo para melhorar seus produtos. OpenAI continua como alternativa com
créditos próprios da API. Depois de configurar o token do bot, ative a conexão. Cada usuário faz o vínculo em **Minha conta → Telegram**, escolhe um
veículo padrão e envia áudio ou texto. Dados completos são salvos automaticamente;
dados ausentes geram perguntas. A confirmação antes de salvar é opcional.

Na troca de OpenAI para Gemini, salve as novas configurações e confirme o novo
provedor em Minha conta. O bot, as contas WordPress, os veículos e os dados são
preservados. O painel identifica falhas de chave, permissão, cota, modelo ou rede
sem guardar mensagens privadas nem credenciais nos diagnósticos.

O WordPress precisa usar HTTPS e ter o agendamento funcionando. A integração
fica desativada até configurar as chaves no site; nenhuma credencial acompanha
o código. Consulte [o manual do Telegram](docs/TELEGRAM.md).

## Meus Gastos (1.4.0)

O segundo serviço do portal registra gastos pessoais por conta WordPress:
valor em reais, descrição, data, categoria, pagamento e estabelecimento.
Há cadastro e edição pelo front-end, categorias com cores e arquivamento,
histórico paginado e CSV, além do dashboard com filtros, distribuição por
categoria, evolução, pagamentos e comparação com o período anterior.

O mesmo bot aceita **“gastei 35 reais no almoço hoje, no Pix”** por áudio ou texto.
Ele identifica o serviço em uma única interpretação, pergunta dados ausentes e
respeita a confirmação opcional. Usuários, histórico de combustível, modelo da IA
e vínculos existentes são preservados. Gastos pessoais pertencem somente à conta.
Consulte [o manual de Meus Gastos](docs/GASTOS.md).

## Próximos serviços

O futuro financeiro pode ser outro plugin, usando as mesmas contas e a mesma
sessão WordPress. O núcleo oferece catálogo, regras de acesso e eventos de
abastecimento com chave estável para integração de despesas.

- [Integração de novos serviços](docs/INTEGRACAO.md)
- [Migração de dados antigos](docs/MIGRACAO.md)
- [Validação](docs/VALIDACAO.md)
- [Manual e perguntas frequentes](readme.txt)

Configure o envio de e-mails do WordPress para recuperação de senha. Exclua a
página do portal e a API `/wp-json/pds/v1/` de caches públicos e de cache offline.
Use HTTPS na publicação. Nenhum backup SQL, credencial de banco ou dado de usuário
deve ser enviado ao repositório.

Licença: GPL-2.0-or-later.

## Meu Financeiro (1.5.0)

O serviço Meus Gastos evoluiu para **Meu Financeiro**, mantendo a rota `gastos`,
`[portal_gastos]`, categorias, despesas e usuários. Agora inclui contas com saldo
inicial, recebimentos realizados/a receber, cartões, faturas, parcelamento,
fluxo de caixa e importação de fatura. Novos abastecimentos geram despesas
automaticamente para quem os registra, com pagamento particular e sem duplicação.

Cadastre o fechamento e vencimento dos cartões. Compras de crédito à vista ou
parceladas são distribuídas pelas faturas; pagar uma fatura baixa o saldo da
conta sem gerar uma segunda despesa. CSV/OFX são lidos localmente. PDFs usam o
Gemini configurado, com autorização na tela e revisão dos dados extraídos.

Veja [FINANCEIRO.md](docs/FINANCEIRO.md) para implantação, regras de fechamento,
formato de importação, parcelas projetadas e limites.

## Recebimentos pelo Telegram (1.6.0)

Diga ou envie por áudio: **“Recebi 100 reais na conta C6 pessoa jurídica.”**
A conta de destino deve estar cadastrada em **Meu Financeiro → Contas**. O bot
registra a entrada, atualiza o saldo e mostra o resumo. Entradas em reservas ficam
fora do fluxo de uso diário. Usa
o mesmo vínculo, provedor e preferência de confirmação já configurados.

`/contas` lista suas contas ativas; `/recebimentos` mostra ajuda. Se valor, data
ou conta estiverem incertos, o bot pergunta e aceita só o complemento. PF/PJ
são reconhecidos; nomes ambíguos nunca escolhem uma conta automaticamente.
Sem origem mencionada, a descrição é **Recebimento**. Previsões de entrada
continuam disponíveis no portal como **A receber**.

## Contas mensais e recebimentos futuros (1.7.0)

Em **Meu Financeiro → Contas a pagar**, registre contas únicas ou mensais, com
valor conhecido ou a definir. Cadastre quantidade de meses ou deixe sem término
definido. A geração mensal cria apenas previsões; **não há baixa automática**.
**Dar baixa** registra valor/data reais e a conta/cartão de pagamento. É possível
desfazer uma baixa, ajustar uma ocorrência ou encerrar a recorrência.

As faturas dos cartões aparecem automaticamente todos os meses, com os valores
das compras e parcelas. Não são copiadas como outra despesa. Recebimentos futuros
ficam **A receber** até **Marcar recebido**, inclusive quando sua data passa.

Exemplos no mesmo Telegram:

- “Cadastre conta de telefone de 80 reais vencendo dia 20, todo mês.”
- “Conta de luz vencendo dia 25, com valor a definir, uma vez.”
- “Vou receber 500 reais dia 10 do mês que vem na conta C6 PJ.”
- “Prevejo receber 3.000 reais todo mês no dia 5 na C6 PJ.”

`/contasapagar` mostra ajuda para previsões. Os vínculos, o modelo da IA e as
preferências de confirmação continuam valendo. Consulte [PLANEJAMENTO.md](docs/PLANEJAMENTO.md).

## Transferências entre contas (1.8.0)

Em **Meu Financeiro → Transferências → Nova transferência**, selecione origem,
destino, valor e a data da transferência realizada. Também há um atalho
**Transferir** em cada conta ativa. O valor reduz o saldo da origem e aumenta o
destino, preservando o total entre as contas e os totais de gastos/recebimentos.
O histórico permite filtrar mês, conta e situação, além de estornar um registro
sem apagá-lo. Veja [as regras financeiras](docs/FINANCEIRO.md#transferências-entre-contas).

## Tipos de conta e datas de pagamento (1.9.0)

Em **Meu Financeiro → Contas → Tipos de conta**, cadastre suas classificações de
contas bancárias e carteiras. Os três tipos iniciais são mantidos; é possível
renomear, arquivar e reativar qualquer tipo, sem remover contas ou movimentações.
As opções aparecem no campo **Tipo de conta** do cadastro da conta financeira.

Na baixa de uma conta e no pagamento de uma fatura, escolha a **Data em que o
pagamento foi feito**. Para corrigir depois, use **Alterar data do pagamento** na
conta paga ou **Alterar data** no pagamento da fatura. O vencimento original e o
valor ficam preservados. Veja [as regras financeiras](docs/FINANCEIRO.md).

## Compras divididas e cartão compartilhado (1.11.0)

Ative **Fatura de cartão compartilhada** no painel **WordPress → Portal de
Serviços**. Cada usuário poderá cadastrar nomes em **Meu Financeiro → Pessoas**.
Ao registrar/editar uma compra no crédito, marque **Este gasto é de outra pessoa
ou será dividido** e escolha: uma pessoa paga tudo, dividir igualmente ou definir
valores por pessoa. Marque **Eu também participo da divisão** para incluir sua parte.

Uma compra de **R$ 500 em 5 vezes**, dividida igualmente entre você e **Isabela
Maeda**, permanece com cinco parcelas de R$ 100 na fatura e gera **cinco
recebimentos previstos de R$ 50** para Isabela, nos vencimentos correspondentes.
É possível adicionar outras pessoas e atribuir valores diferentes a cada uma.
O dashboard mantém todos os gastos juntos. A sua parte não gera recebimento.

Use **Dividir compra** na linha da fatura ou na prévia da importação. Escolha a
parcela inicial; as seguintes adotam a divisão automaticamente, preservando os
acordos das anteriores. Uma compra importada na parcela 3/5 divide somente as
parcelas 3, 4 e 5, sem recriar as anteriores. A prévia só é gravada ao confirmar
a importação. Os reembolsos ficam em **Recebimentos**, com filtro por pessoa e
baixa manual. Veja o [manual de cartão compartilhado](docs/CARTAO-COMPARTILHADO.md).


## Assinaturas mensais no cartão (1.12.0)

Em **Meu Financeiro → Assinaturas**, cadastre os serviços mensais ou ative as
sugestões identificadas pelo nome ou por cobranças presentes em várias faturas.
Uma compra à vista já registrada também tem **Tornar assinatura** nos detalhes
da fatura. Informe nome, descrição na fatura, cartão, categoria, valor e dia.

As assinaturas ativas geram uma cobrança **prevista** na próxima fatura; a cada
fechamento, o próximo mês é incluído automaticamente. Importe a fatura ou use
**Confirmar cobrança** para substituir a previsão por um único gasto realizado.
Reajustes na importação atualizam a cobrança daquele mês sem duplicação. A sua
configuração do valor mensal continua disponível para os próximos meses.

Acompanhe custo mensal e projeção anual. Use **Pausar**, **Ativar**, **Cancelar**
ou **Excluir**, mantendo as cobranças históricas. **Não houve cobrança**, nos
detalhes da fatura, remove somente aquela previsão. Esses controles organizam
os registros do portal; o cancelamento com o fornecedor deve ser feito no próprio
serviço. Consulte o [manual de assinaturas](docs/ASSINATURAS.md).


## Extrato das contas (1.13.0)

Abra **Meu Financeiro → Extratos** ou use **Contas → Ver extrato**. Selecione a
conta e o período para ver saldo anterior, entradas, saídas, saldo final e o saldo
após cada movimento. O histórico inclui recebimentos realizados, gastos pagos
pela conta, pagamentos de fatura e as duas direções de transferências internas.

Use **Editar** ou **Excluir** na própria linha. Corrija data, valor, conta e os
campos de cada origem. Uma transferência é corrigida ou estornada nas duas contas;
um pagamento de fatura mantém as compras; excluir a baixa de uma conta a pagar
reabre essa ocorrência como pendente. Abastecimentos acompanham o registro original.
As confirmações mostram o efeito de cada exclusão antes de aplicar. Consulte o
[manual de extratos](docs/EXTRATOS.md).


## Dashboard pelas parcelas do mês (1.14.0)

O **Dashboard** soma apenas os valores das parcelas das faturas do período. Uma
compra de **R$ 500 em 5 vezes** contribui com **R$ 100 em cada mês da fatura**, e
o valor de R$ 500 fica no **Histórico** e em **Consultar compra**. Categorias,
formas de pagamento, maior lançamento, média e gráficos seguem a mesma regra.

Use o seletor de mês e as setas para conferir os próximos meses. Gastos fora do
cartão continuam pela data da compra; crédito à vista também segue a respectiva
fatura. Parcelas de compras antigas entram no mês correto, sem exigir nova
importação ou duplicar registros. Veja o [manual do dashboard mensal](docs/DASHBOARD-MENSAL.md).
