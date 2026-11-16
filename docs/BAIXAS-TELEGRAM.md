# Baixas de previsões pelo Telegram — 1.17.0

Use o mesmo bot e o mesmo vínculo da conta WordPress para dar baixa em uma conta
a pagar, receber um valor previsto ou registrar o pagamento de uma fatura.
A previsão precisa existir no portal. O bot localiza o lançamento e usa os mesmos
registros financeiros do aplicativo; não cadastra uma segunda despesa ou entrada.

## Exemplos por texto ou áudio

> Dê baixa na conta de luz prevista de novembro: paguei 195 reais ontem no Pix pela conta C6 PJ.

> Recebi o salário previsto de novembro: 2 mil reais hoje na conta C6 pessoa jurídica.

> Recebi da Isabela Maeda o reembolso previsto do cartão, 100 reais hoje na conta C6 PJ.

> Paguei 300 reais da fatura do cartão C6 de novembro pela conta C6 PJ.

Informe a descrição, o mês/data da previsão quando necessário, o valor realmente
realizado e a conta financeira. Nas contas pagas, informe também a forma de
pagamento. A data real pode ser anterior à data da mensagem, mas não futura nem
anterior ao saldo inicial da conta escolhida. Sem data dita, usa-se o dia da
mensagem no fuso configurado no WordPress.

O valor real pode diferir do previsto. Uma conta prevista em R$ 200 pode ser
baixada em R$ 195; a conta preserva seu valor previsto e o gasto usa R$ 195.
Um recebimento previsto usa seu próprio registro, atualizado com o valor e a
data reais. Em recorrências, somente a ocorrência escolhida é baixada.

## Escolher e completar uma previsão

- **/pendentes** consulta previsões pendentes e permite escolher pelos botões.
- **/baixar** mostra a ajuda; **/baixar texto** direciona o reconhecimento para baixa.
- **/confirmar** ou **/salvar** confirma o resumo completo pendente.
- **/cancelar** ou **/novo** descarta a conversa atual, preservando a previsão.
- **/contas** lista os nomes das contas financeiras ativas.

São mostradas até oito opções por consulta. Se houver mais, informe o mês ou
uma descrição mais específica. Nomes semelhantes ou várias ocorrências não são
resolvidos pelo valor mais próximo: escolha a previsão correta. O mês originalmente
previsto é independente da data real do pagamento/recebimento.

Quando faltar informação, responda somente o complemento, por texto ou áudio:
“na C6 pessoa jurídica”, “paguei ontem”, “foi no Pix” ou “195 reais”. PF/pessoa
física e PJ/pessoa jurídica são reconhecidos; uma conta ambígua não é escolhida
silenciosamente. A conta prevista é aproveitada se continuar ativa, quando você
não informar outra. Sem conta válida, o bot pergunta.

A preferência individual e a exigência global de confirmação continuam valendo.
Quando uma mensagem comum de gasto/recebimento corresponde a uma previsão, o bot
pede a revisão da baixa para distinguir essa ação de um lançamento independente.
Para cadastrar um novo gasto/recebimento separado, use explicitamente **/gasto
texto** ou **/recebimento texto**, após cancelar a conversa de baixa se necessário.
Repetições com mesma previsão, valor e data são identificadas para evitar duplicação.

## Efeito nos registros

- **Conta a pagar:** marca a previsão como paga e cria apenas seu gasto vinculado,
  usando valor e data reais. No crédito, a baixa é à vista e entra na fatura;
  o saldo bancário só muda quando a fatura for paga. Parcelar uma compra continua
  sendo feito pelo registro de gasto do portal.
- **Recebimento previsto:** marca o registro existente como recebido, sem criar
  outro. Conta, valor e data realizados determinam o crédito no saldo.
- **Reembolso compartilhado:** preserva o vínculo com a pessoa, a compra e a parcela.
- **Fatura:** usa o pagamento original da fatura, inclusive parcial, limitado ao
  saldo em aberto; não cria outra compra. Para pagar uma fatura, escolha conta
  bancária de saída, em vez de registrar uma nova compra no crédito.
- **Reserva/investimento:** segue a finalidade da conta. Uma entrada diretamente
  na reserva altera seu saldo, sem entrar na disponibilidade de uso diário.

As demais ocorrências de uma recorrência não são baixadas por essa ação.
Vencimentos e cron continuam sem realizar pagamentos ou recebimentos automaticamente.
O portal registra a realização informada por você, sem movimentar dinheiro no banco.

## Correções, duplicações e privacidade

Confira dados já realizados em Contas a pagar, Recebimentos, Faturas e Extratos.
Correções e exclusões continuam disponíveis no portal. Se uma previsão mudar
enquanto há um resumo aberto no Telegram, o resumo anterior deixa de autorizar
a baixa. Se ela for paga, recebida, cancelada ou excluída no portal, a confirmação
antiga não cria outro lançamento.

Cada baixa tem uma identidade própria, associada ao evento do Telegram. Reenvios
da mensagem, dos botões, da fila ou da notificação não repetem os efeitos no saldo.
Falhas ao finalizar a baixa desfazem, na mesma transação, tanto a alteração
financeira quanto o registro da ação.

Somente previsões e contas do próprio usuário WordPress podem ser selecionadas.
A IA interpreta campos da fala; IDs, botões e seleção dos registros vêm do servidor.
IDs internos e os dados completos das previsões não são enviados como contexto de
reconhecimento. As pendências expiram em duas horas e são apagadas ao desvincular.
O histórico de baixas concluídas é incluído na exportação/apagamento de privacidade.
