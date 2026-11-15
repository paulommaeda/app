# Extrato de movimentação das contas

Disponível na versão **1.13.0**. Abra **Meu Financeiro → Extratos** ou, em
**Contas**, clique em **Ver extrato** na conta desejada. Contas arquivadas também
podem ser consultadas. Todos os dados pertencem à mesma conta WordPress.

## Consultar o período

Escolha a conta, a data inicial e a final. O período pode ter até dois anos.
A consulta mostra saldo anterior, entradas, saídas e saldo final do período.
Cada linha tem data, descrição, origem, valor e saldo depois daquele movimento.
A listagem usa até 50 linhas por página; os totais abrangem todo o período e o
saldo acumulado continua corretamente entre as páginas.

Entram no extrato os movimentos que já afetam o saldo da conta:

* Recebimentos com situação Recebido, incluindo reembolsos de outras pessoas.
* Gastos pagos diretamente pela conta, inclusive baixas de contas e abastecimentos.
* Pagamentos realizados de faturas de cartão.
* Transferências concluídas, como saída na origem e entrada no destino.
* Saldo inicial, quando a data de início da conta está no período e o valor é diferente de zero.

Compras no cartão aparecem na fatura; o extrato bancário mostra o pagamento
realizado da fatura. Contas a pagar, recebimentos futuros e assinaturas previstas
continuam em suas áreas até gerarem os respectivos movimentos realizados.

O saldo respeita a data do saldo inicial cadastrada na conta. Registros anteriores
a essa data já são abrangidos por esse saldo e não são somados novamente.
Quando o saldo inicial está dentro do período, seu valor é indicado separadamente
dos totais de entradas e saídas. Um saldo inicial negativo também é preservado.
As linhas seguem data, ordem de cadastro e desempate estável por tipo/ID quando
há lançamentos no mesmo instante. Datas diferentes da data de cadastro são respeitadas.

## Editar os movimentos

Clique em **Editar** na linha. A alteração corrige o registro original e atualiza
os saldos e as telas relacionadas:

* **Recebimento**: valor, data real, conta, descrição e observações. Reembolsos
  mantêm a pessoa, a compra e a previsão original; sua descrição é controlada
  pelo vínculo. Editar uma ocorrência recorrente não altera as demais.
* **Gasto direto**: valor, data, conta, descrição, categoria, pagamento e observações.
* **Pagamento de fatura**: valor, data e conta de saída. A fatura e suas compras
  permanecem. O valor corrigido considera os outros pagamentos e não pode exceder
  o saldo restante da fatura. Uma correção somente de conta/data preserva também
  pagamentos que já tenham crédito excedente em razão de ajustes anteriores.
* **Pagamento de conta a pagar**: corrige o gasto da baixa, mantendo vencimento,
  situação Paga, identidade da ocorrência e as demais recorrências.
* **Transferência**: valor, data, origem, destino e observações. As duas contas
  são atualizadas a partir do mesmo registro, sem criar gasto ou recebimento.
* **Abastecimento**: valor pago, data, conta, forma de pagamento, combustível,
  quilometragem, litros, preço e tanque completo/parcial. O registro do veículo
  é atualizado junto com o gasto; datas e quilometragens seguem as validações
  do serviço Meu Combustível.
* **Saldo inicial**: valor e data de início. A conta e os movimentos posteriores
  permanecem. A data inicial não pode avançar e ocultar movimentos já contabilizados.

Uma mudança de conta faz o movimento sair do extrato anterior e aparecer no da
conta de destino, na data informada. A data deve ser até hoje e igual ou posterior
à data do saldo inicial das contas envolvidas. Contas arquivadas aceitam correções
de seus movimentos existentes; novas origens/destinos devem ser contas ativas.

## Excluir os movimentos

Clique em **Excluir**. A confirmação explica o efeito no caso selecionado:

* Um gasto direto ou recebimento avulso é removido e seu efeito no saldo é desfeito.
* Uma transferência é estornada nas duas contas. O registro permanece como Estornado
  em Transferências e deixa de participar dos extratos e saldos.
* Um pagamento de fatura é removido; o valor retorna ao saldo da conta e ao saldo
  em aberto da fatura. As compras e os outros pagamentos permanecem.
* O pagamento de uma conta a pagar é desfeito e essa ocorrência volta a Pendente.
  A recorrência e as demais ocorrências são mantidas.
* Excluir um abastecimento remove também o registro original do veículo e o gasto
  correspondente. A confirmação informa esse efeito antes de aplicar.
* Um recebimento recorrente ou reembolso é cancelado, mantendo o vínculo para
  impedir que a mesma ocorrência volte a ser gerada. O crédito bancário é desfeito.
* Excluir o saldo inicial zera seu valor. A conta e todos os outros movimentos ficam
  preservados. Para definir um novo valor depois, edite a conta em Contas.

Essas operações alteram os registros do portal. Elas não executam estornos ou
transferências reais em bancos. Toda mudança recalcula o histórico pelo período
real dos movimentos e não altera automaticamente parcelas ou ocorrências futuras.

## Permissões e atualização

Cada usuário acessa somente suas contas e movimentos. Os controles de acesso ao
Financeiro continuam válidos. Correções de abastecimentos também exigem acesso ao
serviço e ao veículo de origem. A leitura usa uma visão consistente dos registros;
correções vinculadas são transacionais para evitar alterações incompletas.

O extrato é calculado dos registros originais. Não há tabela duplicada de saldos
ou movimentos, e as ferramentas de privacidade continuam usando os dados de origem.
A atualização preserva usuários, contas, recebimentos, cartões, faturas, assinaturas,
divisões e os cadastros anteriores.
