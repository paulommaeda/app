=== Portal de Serviços ===
Contributors: paulommaeda
Requires at least: 6.6
Requires PHP: 8.0
Stable tag: 1.17.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Portal de serviços com usuários WordPress, combustível e gastos pessoais.

== Description ==

Uma única instalação reúne cadastro, login, recuperação de senha, conta pessoal,
catálogo de serviços, Meu Combustível e Meu Financeiro.

* Cadastro com papel restrito de cliente, usando wp_users e autenticação nativa.
* Contas WordPress existentes entram com seu e-mail e sua senha atual.
* Veículos privados por proprietário; compartilhamento explícito com outras contas.
* Abastecimentos com edição, exclusão, gasolina, etanol e diesel, preço de tabela,
  litros, valor pago, descontos, observações e tanques completos/parciais.
* Dashboard, evolução do custo/km, relatórios por período e exportação CSV.
* Consumo ponderado entre tanques completos, incluindo abastecimentos parciais.
* Cadastro público configurável e bloqueio de serviços por usuário.
* Layout próprio responsivo; possibilidade de exibir dentro do tema.
* Registro público de módulos e eventos de integração entre serviços.
* API com autenticação, nonces e verificação de acesso a cada recurso.
* Exportação/apagamento pelas ferramentas de privacidade do WordPress.
* Sem JavaScript de CDN ou banco separado; atualizações consultadas no GitHub.
* Importação do banco antigo por SQL/JSON ou leitura das tabelas existentes.
* Telegram com vínculo individual, voz/texto, gastos, recebimentos e abastecimentos.
* Recebimentos realizados ou futuros por voz/texto, com conta de destino e confirmação.
* Baixas de contas e recebimentos previstos pelo Telegram, com escolha da ocorrência.
* Pagamentos parciais de faturas e reembolsos previstos pelo mesmo bot, sem duplicação.
* Contas a pagar únicas ou mensais, com edição, exclusão e baixa manual.
* Correções de contas pagas recalculam o mesmo gasto, o saldo e a fatura.
* Recorrências permitem alterar ou excluir só uma ocorrência ou esta e as próximas.
* Recorrências geram previsões; datas de vencimento nunca dão baixa automática.
* Faturas dos cartões aparecem mensalmente nas contas a pagar com os gastos reais.
* Investimentos e reservas fora do dinheiro disponível e do fluxo de uso diário.
* Resgates e aportes por transferências, disponibilizando somente o valor necessário.
* Finalidade configurável nos tipos de conta; saldos e extratos preservados.
* Gastos, contas, recebimentos, cartões, faturas e dashboard privados por usuário.
* Dashboard mensal soma as parcelas de cada fatura; valor integral fica na consulta da compra.
* Abastecimentos novos geram gastos automaticamente para quem registrou.
* Crédito à vista ou em até 120 parcelas, distribuídas por fechamento/vencimento.
* Importação de fatura CSV/OFX e PDF pelo Gemini, com conferência e parcelas futuras.
* Pagamentos parciais de fatura reduzem o saldo da conta sem duplicar o gasto.
* Divisão opcional de compras entre você e várias pessoas, igual ou por valores.
* Rateio na compra, na fatura ou na prévia de importação, aplicado às próximas parcelas.
* Assinaturas mensais do cartão com identificação de serviços e gestão particular.
* Próxima cobrança prevista após cada fechamento, com conferência sem duplicar o gasto.
* Extratos por conta e período, com entradas, saídas e saldo após cada movimento.
* Edição e exclusão pelo extrato, sincronizadas com recebimentos, gastos e pagamentos.

== Installation ==

1. No WordPress, acesse Plugins > Adicionar plugin > Enviar plugin.
2. Envie portal-de-servicos.zip e clique em Instalar agora e Ativar.
3. A página Portal de Serviços é criada automaticamente com [portal_servicos].
4. Acesse o menu Portal de Serviços para abrir a página e ajustar as opções.
5. Configure o envio de e-mail do WordPress para recuperação de senha.
6. Exclua a página do portal e /wp-json/pds/v1/ do cache da hospedagem/CDN.

Use HTTPS no portal publicado. Não é necessário importar database.sql nem
editar credenciais de banco: o plugin usa a conexão do próprio WordPress.

== Telegram ==

Configure o token do bot e selecione Gemini (Google) ou OpenAI em Portal de Serviços > Telegram.
Gemini pode usar a faixa gratuita da API, sujeita ao modelo e às cotas do projeto.
Crie a chave no Google AI Studio e mantenha o projeto no nível gratuito, sem ativar
faturamento. Na faixa gratuita, o Google pode usar o conteúdo para melhorar seus
produtos. A alternativa OpenAI usa créditos próprios da API.
Ative a conexão em um WordPress com HTTPS. Cada pessoa vincula o próprio Telegram
em Minha conta e escolhe veículo padrão e confirmação. O reconhecimento usa a
chave central da API configurada. Configure o cron da hospedagem para a fila.
Use Testar reconhecimento para conferir a chave e o modelo. Ao trocar o provedor,
os usuários confirmam o novo uso em Minha conta, mantendo seu vínculo e veículo.
Veja docs/TELEGRAM.md. A integração fica desativada até essa configuração.

== Meu Financeiro ==

Abra Meu Financeiro no catálogo do portal. Registre descrição, valor, data e categoria
pelo front-end ou pelo mesmo bot: gastei 35 reais no almoço hoje, no Pix.
Para entradas, diga: recebi 100 reais na conta C6 pessoa jurídica. Cadastre a
conta no portal primeiro. /contas lista suas contas e /recebimentos mostra ajuda.
Categorias podem ser criadas, renomeadas, coloridas, arquivadas e reativadas.
O dashboard mostra totais, evolução, pagamentos e distribuição por categoria com
os mesmos filtros do histórico e da exportação CSV. Os dados pertencem à conta
WordPress e não são compartilhados com participantes dos veículos.
Cadastre suas contas, recebimentos e cartões; acompanhe faturas e parcelas.
Abastecimentos novos entram automaticamente como gastos. Importe faturas em
CSV/OFX ou, com autorização, PDF pelo Gemini. Confira antes de confirmar.
Veja docs/PLANEJAMENTO.md, docs/FINANCEIRO.md e docs/GASTOS.md para funcionamento e limites.

== Shortcodes ==

[portal_servicos] - catálogo de serviços, login e conta pessoal.
[portal_combustivel] - abre diretamente o serviço Meu Combustível.
[portal_gastos] - abre diretamente o serviço Meu Financeiro (rota gastos preservada).

Insira em páginas publicadas. Mantenha apenas um desses shortcodes em cada página.
Se usar uma página existente como principal, selecione-a nas configurações e
insira o shortcode nela. A ativação não muda a página inicial do site.

== Frequently Asked Questions ==

= É preciso outro cadastro para o próximo plugin? =
Não. Os usuários são contas nativas do WordPress. O próximo plugin usa o mesmo
ID, sessão, e-mail e senha e se registra no catálogo pelo núcleo deste plugin.
Consulte docs/INTEGRACAO.md.

= Este pacote já contém controle financeiro? =
Sim. Inclui gastos, contas, recebimentos, cartões, parcelas e faturas, integrado
aos abastecimentos e ao mesmo Telegram. Não realiza pagamentos bancários.
Cobrança de assinaturas do portal e planos pagos não estão implementados.

= O compartilhamento libera outros serviços? =
Não. Libera somente um veículo e seus abastecimentos. O proprietário gerencia
os acessos. Participantes podem editar/apagar os registros que criaram; o
proprietário pode editar/apagar todos. Revogar acesso impede novas consultas.
Dados já visualizados ou exportados pelo participante não podem ser recolhidos.

= O primeiro cliente vira administrador? =
Não. Todo cadastro público recebe somente o papel Cliente do portal.
Administradores continuam sendo definidos pelo WordPress.

= Clientes entram no wp-admin? =
Clientes com o papel criado pelo portal são redirecionados ao portal. Contas
existentes mantêm seus papéis e permissões originais de outros plugins.

= O administrador vê os veículos de todos? =
O painel administrativo mostra totais gerais e permite gerenciar acessos.
O aplicativo pessoal mantém a mesma regra de propriedade/compartilhamento,
inclusive para administradores. O administrador do site continua tendo acesso
às ferramentas e ao banco do WordPress de acordo com suas permissões.

= Como medir consumo? =
Marque Completei o tanque quando efetivamente encher o tanque. O primeiro
tanque completo é a referência. Os litros e valores de abastecimentos parciais
posteriores são acumulados até o próximo tanque completo. Sem um intervalo
completo, consumo e custo/km aparecem como traço. Gastos são somados pela data
do abastecimento, sem rateio artificial entre meses.

= O relatório de um mês usa registros de outros meses? =
Os gastos pertencem apenas às datas selecionadas. O intervalo de distância e
consumo pertence ao abastecimento que o encerra e pode começar antes do período.
As médias são ponderadas pelos volumes dos intervalos válidos.

= Os dados do aplicativo antigo são importados? =
Sim. Abra Portal de Serviços > Importar app antigo e envie o backup SQL/JSON
com os dados ou selecione as tabelas antigas no banco do WordPress. Usuários
são criados no WordPress ou vinculados pelo e-mail; senhas compatíveis são
preservadas e permissões administrativas antigas não são concedidas.
O ZIP de código original não contém dados de produção. Veja docs/MIGRACAO.md.

= O plugin inclui PWA? =
Não registra service worker próprio. Isso evita conflito com o WordPress ou
plugins de PWA do portal. Se usar um PWA, mantenha páginas autenticadas e API
fora do cache offline e valide seu fluxo na hospedagem.

= Posso desativar o combustível depois? =
Sim, desmarque o serviço nas configurações. O núcleo do portal permanece ativo
para os outros plugins registrados. Não desative o plugin inteiro se outros
módulos estiverem usando seu núcleo.

= O que acontece ao desinstalar? =
As tabelas, configurações e contas são preservadas para reinstalação. Excluir
usuários do WordPress remove os veículos de sua propriedade e os respectivos
abastecimentos; autoria em veículos de terceiros é anonimizada. As ferramentas
de privacidade do WordPress permitem exportar e apagar esses dados.

== Changelog ==

= 1.17.0 =
* Baixa de contas a pagar e recebimentos previstos por texto ou áudio no Telegram.
* Reconhecimento distingue previsão cadastrada, realização e novo lançamento.
* Comandos /baixar e /pendentes, com escolha de ocorrência e complementos de informação.
* Valor, data real, conta financeira e forma de pagamento conferidos antes da baixa.
* Recebimentos atualizam o registro original; contas pagas geram somente seu gasto vinculado.
* Recorrências baixam somente a ocorrência escolhida, preservando os próximos meses.
* Faturas aceitam pagamento parcial pelo bot, sem duplicar as compras do cartão.
* Reembolsos compartilhados preservam pessoa, compra e parcela de origem.
* Reservas seguem separadas do fluxo de uso diário ao realizar seus recebimentos.
* Alterações, cancelamentos, exclusões e baixas no portal invalidam confirmações antigas.
* Transações e identidade de evento impedem duplicação após reenvio ou falha de notificação.
* Permissões, consentimento, expiração, desvinculação e privacidade incluem as baixas.

= 1.16.0 =
* Tipo Investimento / reserva e finalidade configurável nos tipos personalizados.
* Saldo reservado separado do dinheiro disponível para uso diário.
* Movimentos das reservas fora do fluxo mensal, preservando seus saldos e extratos.
* Resgates e aportes consideram somente transferências entre reserva e uso diário.
* Transferências internas do mesmo grupo permanecem neutras e sem despesa ou receita.
* Resultado realizado e previsto incluem somente o valor liberado ou reservado.
* Rendimentos e taxas da reserva permanecem nos extratos sem inflar a disponibilidade.
* Contas previstas de reserva ficam fora da projeção diária; faturas em aberto são preservadas.
* Contas existentes podem mudar de tipo e tipos usados podem mudar de finalidade.
* Renomear, arquivar ou reativar preserva a finalidade e o histórico.
* Migração preserva a classificação de contas anteriores; privacidade inclui a finalidade.
* Fluxo de caixa, formulários e filtros de datas adaptados para celular.

= 1.15.0 =
* Botões Editar e Excluir em contas pendentes, pagas e canceladas.
* Edição de descrição, valor previsto, vencimento, categoria, conta e observações.
* Correção de valor pago, data real, forma de pagamento, conta, cartão e categoria do gasto.
* Contas pagas mantêm o mesmo gasto; os saldos e faturas são recalculados sem duplicação.
* Exclusão de uma ocorrência ou desta e das próximas contas recorrentes não pagas.
* Exclusões retiram a conta das previsões e impedem recriação por cron ou confirmação repetida.
* Edição da recorrência a partir da ocorrência escolhida preserva meses anteriores e pagamentos.
* Exclusão de conta paga remove seu gasto e restaura o saldo, com proteção de faturas pagas.
* Confirmação de exclusão e formulários adaptados para computador e celular.
* Cancelar, reabrir e desfazer baixa continuam disponíveis de forma independente.

= 1.14.0 =
* Dashboard soma somente as parcelas do mês da fatura, usando seus valores exatos.
* Compra de R$ 500 em cinco vezes aparece como R$ 100 em cada mês, preservando o valor integral na consulta.
* Categorias, formas de pagamento, maior lançamento, média e evolução seguem a mesma base mensal.
* Lançamentos do dashboard mostram parcela e fatura, inclusive de compras feitas em meses anteriores.
* Seletor de mês com navegação anterior/próximo e mês completo como período inicial.
* Comparação mensal usa o mês anterior completo; filtros de dias seguem o vencimento de cada parcela.
* Histórico e CSV mantêm o valor total cadastrado; Consultar compra abre o registro original.
* Importações, centavos, alterações de compra e cartões arquivados usam o cronograma existente.
* Atualização preserva usuários, compras, parcelas, faturas, pagamentos e saldos das contas.

= 1.13.0 =
* Extrato por conta e período, com saldo anterior, entradas, saídas e saldo final.
* Movimentos em ordem de data, com saldo acumulado correto em todas as páginas.
* Atalho Ver extrato em cada conta, incluindo contas arquivadas e saldo inicial.
* Edição de valor, data, conta, descrição e campos próprios de cada movimento.
* Transferências editáveis na origem e no destino, preservando um único registro.
* Pagamentos de faturas e contas a pagar corrigidos com seus vínculos preservados.
* Abastecimentos editados e excluídos pelo extrato com sincronização do registro original.
* Exclusões recalculam os saldos; transferências são estornadas nas duas contas.
* Recebimentos recorrentes e reembolsos cancelados não são recriados após excluir.
* Limites de fatura, datas iniciais, permissões e transações protegem as correções.
* Layout de extrato e formulários adaptado para computador e celular.

= 1.12.0 =
* Área Assinaturas com serviços mensais por cartão, categoria, valor, dia e observações.
* Identificação de possíveis assinaturas por nome e repetição em faturas diferentes.
* Ativação a partir de uma compra existente ou cadastro manual, sem recriar meses anteriores.
* Previsão da próxima cobrança a cada fechamento, com datas específicas da fatura e meses curtos.
* Ativar, pausar, cancelar, editar e excluir serviços, preservando cobranças e faturas históricas.
* Importação substitui a previsão pelo valor real, mesmo com reajuste, sem duplicar o lançamento.
* Confirmação manual da cobrança e remoção de previsões que não aconteceram.
* Custo mensal e projeção anual; previsões integram faturas, limite e fluxo de caixa previsto.
* Gastos realizados e saldo bancário só mudam na confirmação e no pagamento correspondentes.
* Privacidade, isolamento por usuário, transações e atualização nativa incluem assinaturas.

= 1.11.0 =
* Compras divididas entre duas ou mais pessoas, com participação opcional do titular.
* Divisão igual ou por valores, gerando apenas os recebimentos devidos por outras pessoas.
* Compra de R$ 500 em 5 vezes dividida pela metade gera cinco recebimentos de R$ 50.
* Botão Dividir compra nas faturas e na prévia editável de importação.
* Aplicação a partir da parcela escolhida, mantendo os acordos das parcelas anteriores.
* Importações seguintes mantêm a proporção e atualizam as previsões sem duplicação.
* Distribuição exata dos centavos por parcela e pessoa, com proteção dos valores já recebidos.
* Mesma opção do WordPress, usuários, faturas e totais do dashboard preservados.

= 1.10.0 =
* Fatura de cartão compartilhada opcional, ativada nas configurações do WordPress.
* Pessoas cadastradas apenas pelo nome, privadas por usuário e sem novas contas WordPress.
* Identificação de quem usou o cartão nos gastos e nas compras da fatura normal.
* Recebimentos previstos por parcela, com valor exato e data de vencimento da respectiva fatura.
* Filtro e total a receber por pessoa, com confirmação manual do recebimento na conta escolhida.
* Sincronização de compras, parcelas, vencimentos e importação; proteção de reembolsos já recebidos.
* Desativação preserva dados e recebimentos existentes; privacidade e atualização nativa integradas.

= 1.9.0 =
* Cadastro particular de tipos de conta financeira, com edição, arquivamento e reativação.
* Tipos personalizados disponíveis nas contas; tipos antigos, saldos e históricos preservados.
* Data real do pagamento destacada na baixa de contas e no pagamento das faturas.
* Correção posterior da data de pagamentos, preservando valores, vínculos e vencimentos.
* Saldos históricos e fluxo de caixa seguem as datas corrigidas, sem duplicar lançamentos.
* Privacidade, permissões e atualização nativa incluem os novos cadastros.

= 1.8.0 =
* Transferências entre contas próprias com origem, destino, valor, data e observações.
* Saldos atualizados nas duas contas sem gerar gastos, recebimentos ou distorcer dashboards.
* Histórico mensal com filtros por conta/situação, paginação e estorno do registro.
* Gravação atômica, proteção contra duplicação após falhas de conexão e isolamento por usuário.
* Atalho Transferir em cada conta ativa, interface responsiva e privacidade integrada.
* Validação de contas arquivadas e datas iniciais para manter os dois lados do movimento.

= 1.7.0 =
* Contas a pagar únicas ou mensais, inclusive com valor a definir, e baixa manual com valor/data reais.
* Recorrências de contas e recebimentos futuros, com quantidade de meses ou sem término definido.
* Previsões pendentes até confirmação; geração periódica não altera saldos nem efetua pagamentos.
* Faturas recorrentes integradas às contas a pagar, calculadas pelas compras e parcelas de cada mês.
* Cadastro de contas e previsões de recebimento pelo mesmo Telegram, com confirmação e complementos.
* Edição de ocorrência ou série, encerramento de recorrências e reversão de baixas sem duplicar despesas.
* Fluxo de caixa previsto, valores indefinidos sinalizados, privacidade e atualização nativa preservados.

= 1.6.0 =
* Recebimentos por texto e áudio no mesmo Telegram, usando as contas WordPress existentes.
* Identificação da conta de destino, incluindo pessoa física/PF e pessoa jurídica/PJ.
* Complementos para valor/conta incertos, confirmação opcional e comandos /contas e /recebimentos.
* Saldo e fluxo de caixa atualizados sem criar despesas nem duplicar mensagens recebidas.
* Origem Telegram visível nos recebimentos, pendências privadas e limpeza/apagamento integrados.

= 1.5.0 =
* Meu Financeiro amplia Meus Gastos, preservando contas WordPress e dados existentes.
* Contas com saldo inicial, recebimentos realizados/a receber e fluxo de caixa.
* Cartões com limite, fechamento, vencimento e compras à vista ou parceladas.
* Faturas mensais com parcelas exatas em centavos e pagamentos parciais por conta.
* Abastecimentos integram gastos e faturas, com edição/exclusão sincronizada.
* Importação CSV/OFX local e PDF pelo Gemini, com prévia editável e projeção de parcelas.
* Reconciliação de parcelas futuras, deduplicação e esclarecimento de cartão/parcelas pelo Telegram.
* Privacidade, exportação/apagamento, atualização nativa e isolamento por usuário.

= 1.4.0 =
* Serviço Meus Gastos com despesas e categorias privadas por conta WordPress.
* Dashboard com período, categorias, pagamentos, comparação e evolução de gastos.
* Cadastro, edição, exclusão, histórico paginado e exportação CSV pelo portal.
* O mesmo bot registra gastos pessoais e abastecimentos por voz ou texto, com uma interpretação.
* Esclarecimentos para dados ausentes, confirmação opcional e prevenção de duplicação por serviço.
* Privacidade, bloqueio independente do serviço e preservação dos usuários e dados existentes.

= 1.3.0 =
* Gemini (Google) para áudio e texto em uma solicitação, com opção de faixa gratuita.
* Seleção do provedor e chave protegida no painel, sem exigir chave da OpenAI.
* Troca de provedor mantendo vínculos, veículos e usuários, com renovação do consentimento.
* Teste de reconhecimento e diagnóstico seguro de chave, cota, modelo, permissão e rede.
* Nenhuma troca automática de provedor quando a cota gratuita é atingida.

= 1.2.0 =
* Telegram com vínculo seguro à conta WordPress e veículo padrão por usuário.
* Registro de abastecimentos por áudio ou texto, com reconhecimento pela OpenAI.
* Perguntas para dados ausentes, confirmação opcional e resumo do registro salvo.
* Fila assíncrona, limites por conta e prevenção de duplicação na entrega/gravação.
* Credenciais protegidas e configuração no painel; manual em docs/TELEGRAM.md.

= 1.1.0 =
* Atualização nativa pelo WordPress a partir do GitHub, com integridade do ZIP.
* Importação SQL/JSON ou do banco antigo, com contas nativas WordPress.
* Preservação de senhas compatíveis, vínculos, valores e registros duplicados em KM.
* Processamento retomável e idempotente, sem sobrescrever alterações do novo app.

= 1.0.0 =
* Conversão completa para WordPress, portal modular e serviço Meu Combustível.
