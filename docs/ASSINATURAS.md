# Assinaturas mensais dos cartões

Disponível na versão **1.12.0**, em **Meu Financeiro → Assinaturas**. Cada conta
WordPress gerencia seus serviços, usando seus próprios cartões e categorias.
Não é necessário configurar uma chave de IA para identificar assinaturas.

## Identificar e cadastrar

A área **Possíveis assinaturas identificadas** procura nomes compatíveis com
serviços de assinatura e compras à vista do mesmo estabelecimento em pelo menos
duas faturas diferentes. Analisa até 3.000 compras recentes dos últimos 12 meses
e mostra até 50 sugestões. Parcelamentos não são tratados como assinaturas.
A sugestão não ativa nada sozinha: confira se a cobrança é realmente mensal.
Uma cobrança anual ou uma compra repetida pode ser apenas uma sugestão.

Use **Ativar como assinatura** para revisar nome, descrição que aparece na
fatura, cartão, categoria, valor, dia de cobrança e primeira fatura prevista.
A compra original fica preservada. A recorrência começa depois dela e na próxima
fatura aberta, sem recriar meses antigos. Nos detalhes de uma fatura, compras à
vista têm **Tornar assinatura**; compras já vinculadas têm **Gerenciar assinatura**.
Na prévia de importação, nomes reconhecidos são sinalizados. Após importar,
abra Assinaturas para ativar o serviço identificado.

**Cadastrar assinatura** também permite cadastrar qualquer serviço manualmente.
A primeira fatura é o mês do vencimento; em branco, usa a próxima fatura aberta.
O início pode ser escolhido em até 24 meses. Há até 100 serviços não excluídos
por usuário. É possível cadastrar vários serviços com a mesma descrição na
fatura, mas com nomes próprios diferentes; nesse caso, confira qual previsão
corresponde à cobrança importada.

## Geração mensal e fechamento

Uma assinatura ativa gera sua primeira previsão ao salvar. Depois, a cada
fechamento, gera a cobrança da fatura subsequente. O cartão determina fechamento,
vencimento e a regra das compras no dia do fechamento. Datas específicas de
faturas já ajustadas no portal têm prioridade. Dias 29, 30 e 31 usam o último
dia disponível quando necessário. Cada serviço tem no máximo uma ocorrência
por mês de fatura, mesmo após várias consultas ou execuções do agendamento.

O WordPress verifica a geração a cada hora e também quando você consulta cartões,
faturas, fluxo de caixa ou assinaturas. Em sites com pouco acesso, configure um
cron real da hospedagem para executar o cron do WordPress periodicamente. Se o
site ficar sem executar, a próxima verificação recupera os meses desde a última
geração, em lotes limitados. Cartões/categorias arquivados interrompem a geração
e a área de assinaturas informa o que precisa ser corrigido.

## Previsão e cobrança real

A previsão aparece como **Assinatura · cobrança prevista** nos detalhes da fatura.
Ela integra o total, o comprometimento do cartão e o fluxo de caixa previsto,
mas não cria um gasto realizado no dashboard e não debita uma conta bancária.

Ao importar a cobrança do mesmo serviço, cartão e mês de fatura, a prévia oferece
**Conferir Assinatura**. Quando há uma única correspondência, ela já é selecionada.
A identificação usa a descrição, sem exigir que data ou valor sejam idênticos à
previsão. Confira a seleção e confirme a importação. A previsão é substituída pelo
gasto real na mesma transação. Reimportar a mesma cobrança não cria outro gasto.
Uma compra correspondente registrada manualmente também absorve a previsão.

Sem importar, use **Confirmar cobrança** na fatura e informe valor, data real e
categoria. Apenas datas até hoje são aceitas. Confirmar uma cobrança não paga a
fatura: o saldo da conta só muda quando você registra o pagamento da fatura.
Cobranças confirmadas podem ser divididas entre pessoas pelos controles normais
do cartão compartilhado, quando essa opção está ativada.

Uma diferença de preço importada vale para aquela cobrança. Para continuar com
um novo valor mensal, edite o serviço em Assinaturas. Em faturas com pagamentos,
a confirmação só pode substituir uma previsão pelo mesmo valor; para corrigir
um valor diferente, corrija/remova o pagamento primeiro.

Se a cobrança não ocorreu, use **Não houve cobrança** na fatura. Essa ocorrência
não volta a ser gerada, inclusive se o serviço for pausado e reativado depois.
As próximas ocorrências continuam seguindo a situação da assinatura.

## Gerenciar os serviços

* **Editar** muda o nome, descrição, cartão, categoria, valor, dia e observações.
  Alterações atualizam previsões em faturas abertas e sem pagamentos. A primeira
  fatura registrada permanece como histórico. Cobranças realizadas não mudam.
* **Pausar** interrompe a geração e retira previsões de faturas ainda abertas e sem
  pagamento. **Ativar** retoma na próxima fatura aberta, sem preencher os meses
  da pausa. Uma ocorrência removida porque não aconteceu permanece removida.
* **Cancelar** mantém o cadastro com situação Cancelada e interrompe as próximas
  previsões. Você pode reativar se voltar a contratar o serviço.
* **Excluir** remove o cadastro da lista e retira previsões abertas sem pagamento.
  As cobranças históricas e os gastos realizados ficam preservados. É possível
  cadastrar o serviço novamente, inclusive a partir da mesma compra original.

Previsões de faturas já fechadas ou com pagamentos são preservadas por esses
controles. Para uma previsão fechada que não ocorreu, remova a ocorrência pela
fatura; se houver pagamento, corrija o pagamento primeiro.

Esses controles valem para o portal. Para encerrar a contratação e impedir a
cobrança pelo fornecedor, cancele no site ou aplicativo do próprio serviço.

## Privacidade e atualização

Cadastros, sugestões e previsões são privados por conta WordPress. Permissões,
bloqueio do serviço financeiro, exportação e apagamento de dados do WordPress
incluem assinaturas e suas cobranças. A atualização mantém usuários, cartões,
compras, divisões e configurações anteriores. Não há operações bancárias nem
contato automático com fornecedores.
