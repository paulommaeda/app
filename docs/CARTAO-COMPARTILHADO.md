# Fatura de cartão compartilhada e divisão de compras

Desde **1.18.0**, o portal também oferece financeiros compartilhados com usuários
WordPress e Telegram individuais. As permissões e o financeiro selecionado se aplicam
às operações deste guia. Consulte [FINANCEIRO-COMPARTILHADO.md](FINANCEIRO-COMPARTILHADO.md).

O compartilhamento existe desde **1.10.0**; a divisão entre várias pessoas está
disponível na versão **1.11.0**. A funcionalidade é opcional e começa desativada.

## Ativar e cadastrar nomes

1. No painel WordPress, abra **Portal de Serviços**.
2. Marque **Fatura de cartão compartilhada → Permitir registrar compras de outras
   pessoas e prever os reembolsos** e salve as configurações.
3. No portal, cada usuário abre **Meu Financeiro → Pessoas → Cadastrar pessoa**.
   Basta o nome (2 a 80 caracteres); não há e-mail, senha, convite ou novo usuário
   WordPress. Os nomes e seus gastos pertencem exclusivamente a esse usuário.
4. É possível editar o nome, arquivar ou reativar uma pessoa. Arquivar impede
   novas atribuições, preservando os gastos e reembolsos que já pertencem a ela.

## Registrar uma compra inteira ou dividida

No cadastro ou edição de um gasto, escolha **Cartão de crédito**, o cartão e o
parcelamento. Marque **Este gasto é de outra pessoa ou será dividido** e escolha:

* **Uma pessoa paga tudo**: atribui toda a compra à pessoa selecionada.
* **Dividir igualmente**: adicione as pessoas e marque **Eu também participo da
  divisão** se parte da compra for sua. Sem essa opção, somente as outras pessoas
  participam. É possível incluir até 20 pessoas, além de você.
* **Definir valores por pessoa**: informe quanto cada pessoa deve no conjunto
  das parcelas selecionadas. Com sua participação marcada, o restante é sua
  parte. Sem sua participação, a soma deve corresponder ao valor inteiro a dividir.

Exemplo: **compra de R$ 500 em 5 vezes, metade sua e metade da Isabela**.
A compra continua cadastrada em R$ 500 para consulta. Desde 1.14.0, o dashboard
e a fatura mostram a parcela de R$ 100 em cada mês. Surgem cinco recebimentos **A receber** da Isabela, cada um de
**R$ 50**, com data igual ao vencimento da respectiva fatura. Os R$ 250 da sua
parte não geram recebimentos. Não há outro cartão, fatura ou dashboard separado.

Para valores diferentes, numa compra de R$ 500, atribua R$ 200 à Isabela e R$ 150
a outra pessoa, com sua participação marcada. Sua parte será R$ 150; em cinco
parcelas iguais, os recebimentos serão de R$ 40 e R$ 30 em cada vencimento.
Os centavos são distribuídos de modo que cada parcela e o total de cada pessoa
fechem exatamente com a compra. Pode haver diferença de um centavo entre parcelas.

Compras à vista no cartão geram uma previsão por pessoa. Os mesmos controles
estão no pagamento particular de abastecimentos no cartão. Dados de divisão
permanecem privados do autor; participantes de veículos não acessam seus reembolsos.
O reconhecimento Telegram mantém seus serviços anteriores; a divisão é configurada
pelo portal nesta versão.

## Dividir pela fatura ou pela importação

Em **Faturas**, abra a fatura e use **Dividir compra** na linha desejada. O
formulário começa na parcela exibida. Em **Importar fatura**, o mesmo botão
aparece na prévia, antes de confirmar. Você pode manter as demais edições de
valor, descrição, categoria e correspondência com uma compra já cadastrada.
Definir a divisão na prévia não grava a compra; confirme a importação para aplicá-la.

**Aplicar a partir da parcela** define a primeira parcela que adotará o acordo.
Por exemplo, para uma compra de R$ 500 em 5 vezes já na parcela **3/5**, a metade
da Isabela gera R$ 50 nas parcelas 3, 4 e 5. As parcelas anteriores não são
recriadas. Se já tinham outro acordo, seus recebimentos são mantidos. Para
corrigir toda a compra existente, escolha a primeira parcela no formulário.

Os valores personalizados correspondem ao conjunto das parcelas restantes,
mostrado no formulário. Na parcela 3/5 de R$ 100, restam R$ 300; informar R$ 90
para Isabela representa 30% desse conjunto e gera três previsões de R$ 30.
As próximas importações mantêm a proporção; se uma parcela for corrigida para
R$ 120, a previsão dessa parcela passa a R$ 36. A importação reconcilia parcelas
existentes, mantendo uma única compra e suas previsões, sem duplicar lançamentos.

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

* Mudar participantes, valores ou parcelamento atualiza as previsões pendentes
  a partir da parcela selecionada; as anteriores mantêm o acordo que já tinham.
* Alterar o vencimento de uma fatura atualiza as previsões ligadas a ela.
* Reembolsos já recebidos são protegidos: alterações que os invalidem são
  bloqueadas. Reverta o recebimento ou aplique o novo acordo a parcelas posteriores.
* Desmarcar o compartilhamento remove previsões ainda não recebidas a partir da
  parcela selecionada. Excluir a compra remove suas previsões; uma compra com
  reembolso recebido não pode ser excluída.
* As operações de compra, parcelas e previsões são transacionais: falhas não
  deixam um gasto ou um conjunto incompleto de previsões gravado.

Desativar a opção no WordPress impede novos compartilhamentos e oculta o
cadastro de pessoas. Compras, vínculos e recebimentos existentes são preservados,
continuam sendo mantidos e podem receber baixa. As atribuições integrais antigas
continuam funcionando após atualizar. Não há cobranças automáticas ou operações
bancárias. Exportação e apagamento do WordPress incluem pessoas, divisões e reembolsos.
