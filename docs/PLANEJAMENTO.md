# Contas a pagar e recebimentos previstos

A versão 1.7.0 usa os mesmos usuários, contas, cartões, categorias, vínculos e
configuração da IA. Nenhum vencimento dá baixa automática. O portal registra
previsões e movimentos informados por você; não executa operações bancárias.

## Cadastrar uma conta

Abra **Meu Financeiro → Contas a pagar → Cadastrar conta a pagar**. Informe
descrição, vencimento, categoria e valor previsto, se conhecido. Conta bancária
prevista e observações são opcionais. Para luz ou outra conta variável, deixe
o valor vazio: o lançamento fica **A definir**. Ele é sinalizado e não é somado
como se o valor já fosse conhecido.

Escolha **Uma única vez** ou **Todo mês**. Na recorrência, informe o dia dos
vencimentos seguintes e, se desejar, a quantidade de meses, de 1 a 120. A primeira
data é a informada no formulário; os meses seguintes seguem o dia âncora.
Por exemplo: dia 31 gera 28/29 em fevereiro, 31 em março e 30 em abril.

Sem quantidade, o sistema cria previsões para 12 meses e continua gerando as
seguintes. A geração ocorre nas consultas e pelo agendamento horário do WordPress;
por isso não depende apenas do cron para mostrar os próximos meses. Uma recorrência
pode começar nos últimos 12 ou nos próximos 24 meses. Há até 100 recorrências
ativas por usuário. Uma consulta mensal exibe até 1.000 contas.

## Registrar ou corrigir uma baixa

**Dar baixa** solicita o valor efetivamente pago, a data, a categoria e a
conta de saída ou cartão. A baixa gera uma única despesa. Um valor previsto
de R$ 200,00 pode ser baixado como R$ 195,50, preservando a previsão original
para conferência. O valor real aparece no histórico de gastos e no saldo.

No crédito, a despesa entra na fatura correspondente, à vista no crédito. O
saldo bancário só diminui quando essa fatura for paga. Para parcelar uma compra,
continue usando **Registrar gasto** com o total e o número de parcelas.

**Desfazer baixa** remove a despesa vinculada e restaura a previsão pendente e
seu efeito no saldo. Para mudar valor/data ou excluir o gasto gerado, faça a
correção pela conta original. A partir de 1.13.0, pagamentos feitos por uma conta
bancária também podem ser corrigidos em **Extratos → Editar**; excluir a baixa
pelo extrato reabre somente essa ocorrência como pendente. Compras ligadas a faturas com pagamento registrado
exigem corrigir/remover esse pagamento primeiro, conforme as regras das faturas.
Repetir a mesma baixa não gera uma segunda despesa.

Cancelar uma previsão não registra pagamento. Reabrir uma previsão cancelada
também não movimenta dinheiro. Uma conta vencida continua pendente até você
confirmar a baixa, cancelá-la ou corrigir seus dados.

## Editar ou excluir uma conta — 1.15.0

Cada conta pendente, paga ou cancelada tem os botões **Editar** e **Excluir**.
Na edição, ajuste descrição, valor previsto, vencimento, categoria, conta prevista
e observações. Alterar uma conta pendente não gera gasto nem movimenta o saldo.
Mover o vencimento para outro mês move também sua exibição na lista mensal.

Em uma conta paga, o formulário separa a previsão do **Pagamento realizado**.
Confira valor efetivamente pago, data real, forma de pagamento, conta ou cartão e
categoria do gasto. A correção atualiza o mesmo lançamento, mantendo seu vínculo
com a conta original. Se mudar a conta de pagamento, o valor retorna à conta
anterior e sai da conta corrigida. No crédito, o valor acompanha a fatura.

Em uma ocorrência recorrente pendente, escolha **Somente esta conta** ou
**Esta e as próximas contas pendentes**. A segunda opção começa no mês original
da ocorrência selecionada, mesmo ao consultar um mês futuro, e atualiza a definição
para novas gerações. Pagamentos, cancelamentos, exclusões e ocorrências anteriores
são preservados. Para mudar de mês, use somente esta conta; na série, altere o dia
dentro do mês. Ao manter a data em fevereiro ou mês curto, o dia original da série
continua ancorado, inclusive no dia 31.

**Excluir** abre uma confirmação com o efeito da operação. Uma conta não paga
sai da lista e das previsões. Uma conta paga sai junto com seu gasto vinculado,
recalculando o saldo ou a fatura. Ao excluir uma ocorrência recorrente não paga,
escolha somente ela ou ela e as próximas não pagas. A segunda opção encerra a
recorrência, preservando contas já pagas e ocorrências anteriores. Excluir apenas
uma mantém as próximas e impede que a ocorrência excluída seja recriada.
Fechar a confirmação não altera os registros. A exclusão não tem opção de desfazer.

**Cancelar** continua mantendo uma previsão cancelada que pode ser reaberta.
**Desfazer baixa** continua restaurando a conta pendente. São ações distintas da
exclusão da conta. Faturas automáticas são ajustadas pelo botão **Conferir / pagar
fatura**, por suas compras, datas e pagamentos. Faturas com pagamentos vinculados
exigem corrigir/remover o pagamento antes de alterar ou excluir a cobrança de origem.

## Gerenciar a recorrência

Em **Gerenciar recorrências de contas e recebimentos**, edite descrição,
valor, dia, categoria e conta prevista. A alteração vale para as ocorrências
pendentes deste mês em diante e para novas gerações. Movimentos já realizados,
ocorrências canceladas e meses anteriores são preservados.

**Encerrar recorrência** cancela as previsões pendentes deste mês em diante e
interrompe novas gerações. A confirmação explica esse alcance. Pagamentos e
recebimentos já realizados e previsões de meses anteriores são mantidos.
Para retomar os próximos meses de uma série encerrada, cadastre outra série;
uma ocorrência cancelada também pode ser reaberta individualmente.

## Faturas dos cartões

Todo cartão ativo aparece automaticamente em cada mês a partir do cadastro.
O valor acompanha as compras, parcelas e ajustes da própria fatura; não existe
uma cópia fixa que fique desatualizada. Faturas históricas com lançamentos de
cartões arquivados continuam disponíveis. Sem cobrança, aparece **Sem saldo a
pagar**, sem criar um pagamento fictício.

Use **Conferir / pagar fatura** para ver os lançamentos e registrar pagamento
parcial ou total pela conta bancária. O pagamento reduz o saldo e o valor em
aberto, sem gerar outra despesa. Datas continuam seguindo fechamento e vencimento
do cartão, incluindo os ajustes da fatura já existentes.

## Recebimentos futuros

Use **Recebimentos → Prever recebimento**. Informe valor, data prevista e
conta de destino. **A receber** não altera o saldo, inclusive depois que a
data passa. Também é possível escolher repetição mensal e quantidade de meses.

Quando o valor entrar, abra a própria previsão e use **Marcar recebido**.
Confira o valor e a data reais antes de salvar. Cada ocorrência é independente:
receber uma mensalidade não recebe automaticamente as demais. A conta respeita
a data inicial de saldo cadastrada, como nos outros lançamentos financeiros.

O fluxo de caixa distingue recebido, a receber, gastos realizados, faturas pagas,
faturas abertas e contas previstas. O **resultado previsto** inclui os valores
conhecidos dessas previsões; ele não é o saldo bancário. Contas a definir são
indicadas separadamente porque seu valor ainda não compõe a projeção.

## Pelo Telegram

Use o mesmo bot por texto ou áudio, por exemplo:

> Cadastre conta de telefone de 80 reais vencendo dia 20, todo mês.

> Conta de luz com valor a definir no dia 25, uma vez.

> Vou receber 500 reais dia 10 do mês que vem na conta C6 PJ.

> Prevejo receber 3.000 reais todo mês no dia 5 na conta C6 pessoa jurídica.

O bot pergunta por descrição/vencimento ausentes e dados incertos. A conta bancária
do recebimento precisa estar cadastrada; a conta prevista para pagar uma despesa
pode ser escolhida depois, na baixa. A preferência individual e a exigência global
de confirmação continuam valendo. Confirmar o cadastro de uma previsão não efetua
baixa. `/contasapagar` mostra ajuda; `/contas` lista contas bancárias.

Para baixar uma previsão existente, use o lançamento original no portal. Um gasto
ou recebimento realizado informado separadamente ao bot é um novo movimento;
ele não é automaticamente conciliado com uma previsão anterior. Isso evita dar
baixa na obrigação errada. Mantenha um único movimento usando os botões de baixa
da previsão original.

## Dados e preservação

Todas as previsões pertencem ao usuário WordPress. Compartilhar um veículo não
compartilha contas, cartões ou recorrências. Exportação/apagamento de privacidade
inclui as definições e suas ocorrências. Desvincular o Telegram remove pendências
de conversa; preserva os lançamentos financeiros já salvos. Excluir os dados do
usuário remove as definições para que a geração não recrie previsões apagadas.

O sistema não importa automaticamente contas de concessionárias nem adivinha
valores variáveis. Confira e ajuste o valor real da conta ou da receita antes
de registrar sua realização.
