# Fatura de cartão compartilhada

Disponível a partir da versão **1.10.0**. É uma opção do portal e começa desativada.

## Ativar e cadastrar nomes

1. No painel WordPress, abra **Portal de Serviços**.
2. Marque **Fatura de cartão compartilhada → Permitir registrar compras de outras
   pessoas e prever os reembolsos** e salve as configurações.
3. No portal, cada usuário abre **Meu Financeiro → Pessoas → Cadastrar pessoa**.
   Basta o nome (2 a 80 caracteres); não há e-mail, senha, convite ou novo usuário
   WordPress. Os nomes e seus gastos pertencem exclusivamente a esse usuário.
4. É possível editar o nome, arquivar ou reativar uma pessoa. Arquivar impede
   novas atribuições, preservando os gastos e reembolsos que já pertencem a ela.

## Registrar uma compra

No cadastro ou edição de um gasto, escolha **Cartão de crédito**, o cartão e o
parcelamento. Marque **Este gasto é de outra pessoa** e escolha **Quem usou o
cartão?**. Cada compra é atribuída integralmente a uma pessoa. Não é necessário
cadastrar outro cartão ou separar uma fatura.

Exemplo: **Isabela Maeda gastou R$ 200 em 4 vezes**. O gasto continua em R$ 200
no dashboard e em quatro parcelas de R$ 50 nas faturas normais. Surgem quatro
recebimentos **A receber**, atribuídos à Isabela, cada um com R$ 50 e data igual
ao vencimento da sua fatura. Os valores usam as parcelas efetivamente geradas,
inclusive diferenças de um centavo em divisões como R$ 100 em 3 vezes.

O cartão à vista gera um único recebimento previsto. Em compras importadas com
parcelas já transcorridas, apenas as parcelas restantes existentes no aplicativo
geram reembolsos. Após importar, edite o gasto para atribuí-lo à pessoa. O
reconhecimento Telegram continua com seus serviços anteriores; esta versão
oferece a atribuição da pessoa pelo portal.

O mesmo identificador pode ser usado no pagamento particular de abastecimentos
no cartão. Dados de pessoa/reembolso permanecem privados de quem registrou o
gasto e não são compartilhados com participantes de um veículo.

## Consultar e receber

Em **Recebimentos**, filtre por **Pessoa**. O total **A receber desta pessoa no
período** soma apenas as previsões pendentes, respeitando as datas filtradas.
Com a opção ativada, a tela inclui os próximos 12 meses inicialmente; ajuste o
campo **Até** para conferir parcelamentos mais longos. A listagem mostra até
1.000 registros e o total é calculado sobre todo o período selecionado.

A previsão é criada sem uma conta bancária de destino. Ela não aumenta saldo e
não é recebida automaticamente, nem por vencer nem quando a fatura é paga.
Use **Marcar recebido**, escolha a conta de destino e confirme valor e data reais.
A confirmação dá baixa na previsão inteira com o valor efetivamente informado;
não divide a mesma previsão em vários recebimentos parciais.

Enquanto for uma previsão, valor e data acompanham a compra/fatura. Para corrigir
um recebimento realizado, edite o recebimento. Voltar sua situação para **A
receber** desfaz o crédito e restaura a previsão original da parcela. **Cancelar
reembolso** mantém o vínculo e a situação Cancelado; edições da compra não
recriam uma previsão cancelada. É possível restaurá-la pela edição da situação.

## Correções e preservação

* Mudar pessoa, valor ou parcelamento atualiza as previsões pendentes, com as
  mesmas identidades sempre que a parcela continua existindo.
* Alterar o vencimento de uma fatura atualiza as previsões ligadas a ela.
* Reembolsos já recebidos são protegidos: alterações que os invalidem são
  bloqueadas. Reverta o recebimento antes de mudar o acordo dessa parcela.
* Desmarcar o compartilhamento ou excluir uma compra remove suas previsões ainda
  não recebidas. Uma compra com reembolso recebido não pode ser excluída.
* A conferência da importação de fatura sincroniza alterações de valor nas
  parcelas e nos respectivos reembolsos pendentes, sem duplicação.
* As operações de compra, parcelas e previsões são transacionais: falhas não
  deixam um gasto ou um conjunto incompleto de previsões gravado.

Desativar a opção no WordPress impede novos compartilhamentos e oculta o
cadastro de pessoas. Compras, vínculos e recebimentos existentes são preservados,
continuam sendo mantidos e podem receber baixa. Não há cobranças automáticas ou
operações bancárias. As ferramentas de exportação e apagamento do WordPress
incluem nomes, atribuições e reembolsos.
