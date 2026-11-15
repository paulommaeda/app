# Investimentos e reservas fora do fluxo mensal — 1.16.0

Use contas de investimento para registrar dinheiro que deve continuar reservado.
O saldo e os movimentos dessas contas permanecem nos seus extratos, separados do
dinheiro disponível para uso diário. Não há compra, venda ou resgate automático
de aplicações financeiras: o portal registra as operações que você realizou.

## Cadastrar uma reserva

1. Abra **Meu Financeiro → Contas → Cadastrar conta**.
2. Informe o nome, o saldo inicial e sua data de referência.
3. Escolha **Investimento / reserva** no tipo de conta e salve.

Para uma conta já cadastrada, use **Editar** e altere seu tipo. Em **Contas →
Tipos de conta**, também é possível cadastrar tipos próprios, como Reserva de
emergência, selecionando a finalidade **Investimento / reserva — fora do fluxo
mensal**. Um nome personalizado que contenha “investimento” não muda a finalidade
automaticamente: escolha-a no formulário.

Conta corrente, poupança e dinheiro/carteira existentes continuam como uso diário.
Se utilizar uma poupança como reserva, altere a finalidade desse tipo ou escolha
outro tipo na conta específica. A finalidade vale para todas as contas vinculadas
ao tipo e também reclassifica a apresentação do histórico do fluxo. Os saldos,
transferências, gastos e extratos originais são preservados. Renomear, arquivar
ou reativar um tipo mantém sua finalidade.

## Disponibilizar apenas o necessário

Na reserva, clique em **Resgatar / transferir**, escolha uma conta de uso diário,
informe somente o valor necessário e a data realizada. Essa operação também pode
ser registrada em **Transferências → Nova transferência**.

Exemplo sem outros movimentos:

| Operação | Disponível para uso | Investimentos e reservas | Efeito no fluxo do mês |
| --- | ---: | ---: | --- |
| Saldo inicial | R$ 1.000,00 | R$ 10.000,00 | R$ 0,00 |
| Resgate de R$ 800,00 para conta corrente | R$ 1.800,00 | R$ 9.200,00 | Entrada de R$ 800,00 em resgates |
| Aporte de R$ 300,00 para a reserva | R$ 1.500,00 | R$ 9.500,00 | Saída de R$ 300,00 em aportes |

O resultado mensal dessas transferências é R$ 500,00, sem registrar receita ou
despesa fictícia. O total entre as contas continua R$ 11.000,00. Transferências
entre duas contas de uso diário ou entre duas reservas não alteram o resultado
do fluxo. Corrigir valor/data ou estornar uma transferência recalcula seus efeitos.

## O que o fluxo de caixa mostra

- **Disponível para uso hoje:** soma dos saldos atuais das contas ativas de uso diário.
- **Investimentos e reservas hoje:** soma separada dos saldos reservados ativos.
- **Recebido e A receber:** apenas entradas em uso diário ou ainda sem conta definida.
- **Gastos diretos e Faturas pagas:** apenas saídas de contas de uso diário ou legadas
  sem conta vinculada. Pagamentos diretamente pela reserva ficam em seu extrato.
- **Resgatado da reserva:** transferências realizadas da reserva para uso diário.
- **Aportado à reserva:** transferências realizadas de uso diário para reserva.
- **Contas a pagar:** previsões de uso diário ou sem conta escolhida. Contas previstas
  especificamente para uma reserva continuam em Contas a pagar, fora dessa projeção.

O resultado realizado é recebido + resgatado − aportado − gastos diretos − faturas
pagas. O resultado previsto acrescenta os recebimentos pendentes e desconta contas
a pagar e faturas em aberto. Faturas em aberto continuam sendo obrigações do cartão,
independentemente da conta que será escolhida para pagar.

As datas do filtro determinam os movimentos do período. Os dois cartões de saldo
identificados como **hoje** sempre mostram a posição atual, inclusive ao consultar
outro mês. Saldo inicial não é receita mensal, e resultado do período não é saldo.
Contas arquivadas ficam fora dos totais de saldo ativo, preservando seus movimentos
históricos e a classificação reserva/uso diário.

Rendimentos, taxas e outros movimentos registrados diretamente na reserva continuam
alterando seu saldo e aparecendo no extrato. Eles não entram no fluxo de uso diário.
O Dashboard de gastos continua mostrando despesas reais nas respectivas categorias;
aportes e resgates não são despesas e não alteram esse dashboard.

## Dados existentes e acesso

A atualização acrescenta uma finalidade ao cadastro de tipos, preservando os tipos
anteriores como uso diário e os usuários WordPress existentes. Cada pessoa mantém
seus próprios tipos, contas e transferências. A finalidade é incluída nas ferramentas
de exportação e apagamento de privacidade do WordPress.
