# Meu Financeiro

A versão 1.5.0 amplia Meus Gastos no próprio plugin, mantendo usuários WordPress,
senhas, vínculos Telegram, categorias, gastos, veículos e configurações da IA.
O serviço continua com identificador `gastos` e shortcode `[portal_gastos]`.

## Começar

1. Atualize em **Plugins** no WordPress. Também é possível enviar o ZIP instalável
   e substituir a instalação atual. Faça o backup habitual do site antes de atualizar.
2. Abra **Meu Financeiro → Contas**, cadastre suas contas/carteiras e informe
   o saldo inicial e a data de referência.
3. Em **Cartões**, cadastre nome, fechamento, vencimento e limite opcional.
4. Registre recebimentos, gastos e abastecimentos. Confira o **Fluxo de caixa**
   e as **Faturas** dos meses correspondentes.

Não há novo cadastro ou chave de IA para os usuários. O Gemini já configurado é
usado para voz e, se autorizado na tela de importação, para reconhecer PDFs.
A chave e o modelo existentes não são sobrescritos pela atualização.

## Contas e recebimentos

Contas podem ser corrente, poupança ou dinheiro/carteira. Informe o saldo **antes
dos movimentos da data inicial**. O saldo atual considera, da data inicial até
hoje: saldo inicial + entradas recebidas − gastos diretos vinculados à conta −
pagamentos de faturas. Movimentos anteriores ficam no histórico e não alteram
esse saldo. Saldo inicial negativo é permitido. Não há sincronização bancária.

Em **Recebimentos**, informe descrição, valor, data, conta de destino e situação.
**A receber** permite datas futuras e não aumenta o saldo. **Marcar recebido**
abre a confirmação dos dados com a data de hoje; salve a data e o valor reais.
Correções e exclusões recalculam os saldos. Contas e cartões podem ser arquivados
sem perder o histórico e reativados depois.

## Compras e faturas

Um gasto direto pode indicar uma conta de saída. Sem conta vinculada, aparece
nos gastos e no fluxo, mas não altera o saldo de uma conta específica.

No crédito, selecione o cartão e **À vista** (uma parcela) ou **Parcelado**,
informando o número de parcelas, de 2 a 120. **Valor** é o **total da compra**,
e não o valor de uma parcela. O formulário mostra a previsão das faturas.
R$ 100,00 em três vezes gera R$ 33,34 + R$ 33,33 + R$ 33,33, sem perder centavos.

O mês da fatura é o mês de **vencimento**. Por exemplo, fechamento dia 5 e
vencimento dia 12: compra dia 4 entra na fatura desse mês; compra dia 6 entra
na do mês seguinte. Configure se a compra no próprio dia do fechamento entra
na próxima fatura ou na que fecha nesse dia. Quando o fechamento ocorre depois
do dia de vencimento, ele pertence ao mês anterior ao vencimento.

Dias inexistentes, como 31 de fevereiro, usam o último dia do mês. Alterar uma
regra de cartão vale para novos ciclos; faturas existentes conservam suas datas.
Em **Conferir fatura → Ajustar datas**, é possível corrigir o fechamento e o
vencimento reais sem mover parcelas já distribuídas. Novas compras consultam
o fechamento corrigido daquele ciclo. Se o banco processou uma compra em outro
ciclo, ajuste **primeira fatura** no gasto. Feriados bancários não são inferidos.

O limite comprometido inclui parcelas futuras, ajustes e pagamentos registrados.
O limite informado é de acompanhamento e não bloqueia um gasto real que o exceda.
Não são guardados número completo do cartão ou código de segurança.

Em **Faturas → Conferir fatura → Registrar pagamento**, escolha conta, valor e
data do pagamento realizado. Pagamentos parciais são permitidos até o saldo em
aberto. Isso reduz o saldo da conta e a fatura, sem criar um segundo gasto.
O portal não executa pagamentos bancários. Remover um pagamento incorreto
reabre o saldo da fatura e restaura o saldo da conta.

Compras com parcelas em fatura que já tem pagamento não podem ter valor,
cartão/parcelamento alterados nem ser excluídas antes de corrigir/remover o
pagamento. Ajustes positivos representam acréscimos; negativos, créditos ou
estornos. Um crédito excedente fica indicado na própria fatura; confira com o
banco como ele foi aproveitado antes de registrar ajuste na próxima.

## Abastecimentos integrados

Um novo abastecimento cria automaticamente um gasto vinculado, na categoria
**Combustível**, para **quem o registrou**. O valor é o efetivamente pago,
incluindo desconto/acréscimo. A tela de abastecimento permite conta, cartão e
parcelamento. O mesmo vale para abastecimento recebido pelo Telegram.

Editar valor/data do abastecimento atualiza o gasto e suas parcelas na mesma
transação; excluir o abastecimento exclui o espelho financeiro. Se uma fatura
envolvida tem pagamento, corrija esse pagamento antes. No financeiro é possível
alterar pagamento, conta, categoria e observações do espelho; valor/data e
exclusão são controlados pelo registro de combustível.

O compartilhamento do veículo não compartilha contas, cartões ou pagamentos
particulares. O proprietário pode corrigir o abastecimento de um participante,
mas não substituir seus dados particulares de pagamento. Cada um vê seus gastos.

A atualização preserva os abastecimentos anteriores e não lança todo o histórico
automaticamente, evitando duplicar gastos que já tenham sido cadastrados à mão.
Editar um abastecimento anterior cria seu espelho se ainda não existir. Registros
novos importados pelo importador legado também geram o espelho. Confira eventuais
gastos manuais anteriores para não manter a mesma despesa duas vezes.

## Importar fatura e projetar parcelas

Em **Importar fatura**, escolha cartão, mês de **vencimento** e arquivo:

| Formato | Processamento | Limite |
| --- | --- | --- |
| CSV UTF-8 ou Windows-1252 | No servidor, com separador ponto e vírgula, vírgula ou tabulação | 3 MB |
| OFX de cartão | No servidor, lê STMTTRN e identificadores FITID | 3 MB |
| PDF sem senha e legível | Gemini configurado no portal, com autorização específica | 8 MB; 5 arquivos por usuário/dia |

CSV deve ter cabeçalhos **Data;Descrição;Valor**. Colunas opcionais: **Parcela**,
**Total de parcelas**, **Categoria**, **ID**. Datas aceitam DD/MM/AAAA ou
AAAA-MM-DD. Baixe o modelo na tela. **Valor é o cobrado na fatura atual**;
créditos/estornos são negativos. OFX usa a convenção de débito negativo para
compras e crédito positivo para estornos; pagamentos anteriores são ignorados
como compra quando identificados pela descrição. Confira todos os sinais.

```csv
Data;Descrição;Valor;Parcela;Total de parcelas;Categoria
01/10/2026;Notebook;100,00;3;10;Compras
02/11/2026;Almoço;35,90;1;1;Alimentação
03/11/2026;Estorno da loja;-10,00;1;1;Outros
```

O exemplo do notebook lança R$ 100,00 na fatura escolhida como parcela 3/10
e projeta sete parcelas de R$ 100,00 nas próximas faturas. Não recria as duas
anteriores nem inventa o total original desconhecido; no histórico, o valor
dessa compra importada representa os **R$ 800,00 restantes** conhecidos.
Parcelas futuras são previsões e podem diferir da cobrança real por juros,
ajustes e arredondamento. A importação seguinte pode conferi-las e corrigir
o valor efetivamente cobrado sem criar outra série de parcelas.

A prévia permite editar descrição, data, valor, categoria e posição da parcela,
além de selecionar/ignorar linhas. Linhas repetidas, pagamentos de fatura e dados
incertos vêm desmarcados. Compare o total selecionado com a fatura. No PDF, o
total explicitamente identificado no documento também aparece para comparação.
Não há lançamentos antes de marcar a conferência e confirmar a importação.

IDs do banco e assinaturas das linhas impedem repetir a mesma importação. A
conferência automática de uma parcela prevista exige cartão, mês, posição,
data e descrição compatíveis. Descrições diferentes entre formatos/meses podem
exigir revisão manual: a identificação não garante detectar toda repetição
entre arquivos de bancos/formatações diferentes. Evite reimportar o mesmo
lançamento em formatos diferentes sem conferir o histórico. Duas compras
realmente idênticas no mesmo arquivo são preservadas como linhas distintas.

Até 300 linhas e 5.000 parcelas geradas por importação. O processamento ocorre
em lotes de 20; após falha de conexão, o botão continua de onde parou. Linhas com
erro não são gravadas e são listadas ao final; corrija o arquivo e gere outra
prévia. Prévias são privadas, expiram em 30 minutos e podem ser descartadas.
O arquivo original não é armazenado pelo plugin.

PDF depende da chave, cota e capacidade do modelo Gemini configurado. O conteúdo
é enviado ao Google somente com autorização na tela; na faixa gratuita, o
provedor pode usá-lo para melhorar produtos, conforme suas condições. CSV/OFX
continuam disponíveis sem IA. Confira resultados de IA com o documento original;
um PDF de cada banco precisa ser validado com a configuração real do portal.

## Visões e Telegram

**Dashboard de gastos**: compras pela data da compra, valor total uma vez,
categorias e hábitos. **Fluxo de caixa**: recebimentos realizados, saídas diretas,
pagamentos de fatura, valores a receber e faturas abertas do período. O resultado
previsto não é o saldo bancário. **Faturas**: parcelas pelo mês de vencimento.

No Telegram, diga, por exemplo: “Comprei um celular de R$ 1.200 no cartão
principal, em seis vezes.” Informe o total. Se só disser crédito, o bot pergunta
se foi à vista ou parcelado; com vários cartões, pergunta qual. Nome/final do
cartão é resolvido contra os cartões da própria conta. Para Pix/débito, é possível
dizer o nome da conta de saída. As preferências de confirmação continuam valendo.

Os dados pertencem ao usuário WordPress e entram na exportação/apagamento das
ferramentas de privacidade. Desinstalar preserva tabelas para reinstalação.
Não há conexão bancária automática, transferência entre contas, investimentos,
pagamentos bancários ou cobrança de assinaturas do portal nesta versão.
