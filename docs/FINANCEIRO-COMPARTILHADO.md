# Financeiro compartilhado — 1.18.0

O financeiro pode ser usado individualmente ou com outras pessoas. Cada participante
mantém seu usuário e sua senha do WordPress e pode vincular o próprio Telegram ao
mesmo bot. Não é necessário compartilhar senha, telefone ou acesso ao painel administrativo.

## Criar e compartilhar

1. Atualize o plugin no WordPress para **1.18.0**.
2. Abra **Meu Financeiro → Criar compartilhado** e dê um nome, por exemplo Família Maeda.
3. Escolha **Criar um financeiro vazio** ou **Compartilhar meu financeiro pessoal atual**.
4. A segunda opção transforma o financeiro existente em compartilhado, preservando
   IDs, saldos, gastos, cartões, faturas, parcelas, divisões, assinaturas e previsões.
   Confirme a opção no formulário. Um novo financeiro pessoal vazio é criado para você.
5. No financeiro compartilhado, abra **Compartilhamento e atividade → Convidar pessoa**.
6. Informe o e-mail e a permissão. O convite vale por sete dias. O portal envia o
   convite pelo e-mail do WordPress e mostra um link que pode ser copiado se o envio falhar.
7. A pessoa abre o link, entra ou cria uma conta WordPress **com o e-mail convidado**
   e clica em **Aceitar convite**. A aceitação é individual e de uso único.

Os dados pessoais do convidado continuam no financeiro dele. Aceitar um convite não
copia ou mistura dados de financeiros diferentes. O proprietário pode criar até vinte
financeiros compartilhados e emitir até trinta convites por grupo ao dia.

## Permissões

| Acesso | Permite |
| --- | --- |
| Consulta | Consultar dashboard, histórico, fluxo, extratos, cadastros, faturas e atividade; exportar os dados acessíveis. |
| Lançamentos | Consulta e criação de gastos, recebimentos, contas previstas, recorrências e transferências; baixa de previsões e pagamento de faturas. |
| Administração | Também editar/excluir lançamentos, gerenciar contas/cartões/categorias, importações, assinaturas e participantes de consulta/lançamentos. |
| Proprietário | Administração e definição/remoção de administradores. |

Somente o proprietário nomeia ou remove administradores. O proprietário não pode ser
removido nem sair do próprio grupo. Participantes podem sair e administradores podem
revogar o acesso de outros participantes conforme essas permissões. A revogação vale
para o portal e o Telegram, inclusive confirmações antigas e notificações aguardando envio.

Acesso de lançamentos permite realizar uma previsão pendente com valor, data e conta
reais; não permite reescrever a descrição ou reverter uma baixa. Edição/exclusão de um
abastecimento já vinculado ao financeiro compartilhado também exige administração,
além da autorização sobre o veículo. Consultas e prévias nunca autorizam alterações.

## Escolher onde registrar

O seletor **Financeiro em uso** indica o destino dos cadastros, lançamentos,
consultas, importações e relatórios no portal. A escolha fica associada ao navegador
e ao usuário. Cada aba conserva seu contexto: uma troca em outra aba não redireciona
um formulário aberto. Ao trocar no seletor, a página é recarregada.

Novos abastecimentos também seguem o financeiro escolhido no portal/Telegram.
Abastecimentos já vinculados conservam seu financeiro de origem durante as correções.
Compartilhar um veículo, por si só, continua sem conceder acesso ao financeiro.

## Telegram de cada participante

1. Entre com seu próprio usuário no portal e abra **Minha conta → Telegram**.
2. Autorize o reconhecimento e vincule o Telegram pelo código, como no uso individual.
3. No bot, envie **/financeiros**. A lista mostra somente financeiros aos quais você tem acesso.
4. Envie **/financeiro ID** usando o número mostrado na lista e aguarde a confirmação.
5. Depois envie o gasto, recebimento, previsão ou baixa por texto ou áudio normalmente.

Exemplos: “Gastei 80 reais no mercado pela C6 PJ”, “Recebi 100 reais na C6 PJ” ou
“Paguei 195 reais da luz prevista de novembro pela C6 PJ”. As contas, cartões,
categorias e previsões são procurados no financeiro selecionado.

A resposta identifica o financeiro. A escolha do bot é independente da escolha no
portal. Uma mensagem já recebida conserva o destino original na fila. Termine ou use
**/cancelar** em uma pendência antes de trocar de financeiro. Usuários com acesso de
consulta podem listar contas/categorias e acompanhar o portal, sem registrar movimentos.
Cada participante tem seu próprio limite de reconhecimento e seu próprio consentimento.

## Autoria e dados anteriores

O histórico e a área **Atividade dos lançamentos** mostram quem registrou os movimentos.
Uma edição preserva o autor original e registra a pessoa que alterou o lançamento.
Remover um participante preserva os gastos, recebimentos, parcelas e saldos existentes.

Na atualização, os dados anteriores são vinculados automaticamente ao financeiro
pessoal do respectivo proprietário WordPress. Não se criam novos usuários nem se
alteram senhas ou vínculos Telegram. As parcelas, regras de fechamento, divisões de
compras e investimentos mantêm o funcionamento anterior.

O cartão compartilhado com pessoas cadastradas apenas pelo nome continua sendo uma
opção independente: gera recebimentos futuros de reembolso e não concede login ou
participação no grupo. Para dar acesso ao portal, use o convite por e-mail.

As ferramentas de privacidade do WordPress exportam os dados próprios e as contribuições.
Apagar um participante anonimiza sua autoria nos financeiros de terceiros, preservando
os registros. Apagar o proprietário remove os financeiros que pertencem a ele.
