# Dashboard de gastos pelas parcelas do mês

A partir da versão **1.14.0**, o Dashboard mostra o impacto das compras no período
conforme as parcelas das faturas. Os dados já cadastrados adotam a nova visão
imediatamente: não é necessário registrar novamente ou reimportar as compras.

## Exemplo: R$ 500 em 5 vezes

A compra fica cadastrada com valor integral de **R$ 500**. O cartão gera cinco
parcelas de **R$ 100**, conforme fechamento e vencimento. O Dashboard mostra:

| Mês da fatura | Valor da compra no dashboard |
| --- | ---: |
| Primeira fatura | R$ 100 |
| Segunda fatura | R$ 100 |
| Terceira fatura | R$ 100 |
| Quarta fatura | R$ 100 |
| Quinta fatura | R$ 100 |

Uma seleção que abrange os cinco meses soma R$ 500 uma única vez, pela soma das
parcelas. Depois da quinta fatura, essa compra deixa de contribuir nos meses
seguintes. A categoria Compras, a forma de pagamento, o maior lançamento, a média
diária, a comparação e a evolução usam a mesma base.

## Escolher o mês ou o período

Use o campo de mês e as setas no Dashboard. A tela começa no mês completo,
inclusive quando o vencimento da fatura ainda está por vir. A comparação de um
mês completo usa o mês anterior completo, respeitando diferenças de 28 a 31 dias.

Os campos De/Até continuam aceitando um período personalizado. Gastos no cartão
entram pela **data de vencimento da fatura**; um intervalo que termina antes do
vencimento não inclui essa parcela. Na evolução diária, a parcela aparece no dia
do vencimento. Gastos fora do cartão entram na data da compra. Em períodos longos,
a evolução é agrupada por mês. Os filtros de categoria, pagamento e descrição
consideram também parcelas de compras registradas em meses anteriores.

Compras no dia ou após o fechamento seguem a regra configurada no cartão; podem
começar na fatura do mês seguinte. Compras à vista no cartão também entram no mês
da sua fatura. Cartões e categorias arquivados mantêm suas parcelas no histórico.
Registros antigos de crédito sem cartão vinculado continuam na data original,
pois não possuem faturas ou parcelamento cadastrado.

## Consultar o valor integral

Em **Lançamentos do período**, cada compra do cartão mostra a parcela, a posição
como 2/5 e o mês da fatura. Use **Consultar compra** para abrir o registro original,
com o valor integral e os dados da compra. Uma alteração nesse formulário usa o
valor total da compra, e o cronograma é recalculado pelas regras normais do cartão.

O **Histórico** e o CSV de compras continuam pela data da compra e com o valor
total cadastrado. Em uma compra importada já na parcela 4/6, o total registrado
pode representar apenas as parcelas restantes conhecidas; a observação de origem
explica esse caso. O Dashboard usa os valores individuais das parcelas existentes.

## Importações, arredondamentos e outros serviços

Uma compra importada na parcela 4/6 entra naquele mês e nos dois meses seguintes,
sem recriar as três parcelas anteriores. A conferência de uma parcela atualiza
somente os valores efetivos afetados. Os relatórios usam o cronograma gravado,
inclusive diferenças de um centavo: R$ 100 em 3 vezes pode resultar em R$ 33,34,
R$ 33,33 e R$ 33,33, fechando exatamente R$ 100.

Compras compartilhadas mantêm a parcela inteira no Dashboard; os recebimentos de
outras pessoas continuam separados em Recebimentos. Previsões de assinaturas
permanecem previstas até a cobrança ser confirmada. Depois da confirmação, o gasto
entra uma única vez no mês da fatura. Pagar a fatura altera o saldo da conta e o
fluxo de caixa, sem acrescentar outra despesa às categorias do Dashboard.

A atualização não muda usuários, valores integrais, parcelas, faturas, pagamentos
ou saldos. Apenas a visão do Dashboard passa a usar os lançamentos do período.
