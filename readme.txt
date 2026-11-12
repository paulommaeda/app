=== Portal de Serviços ===
Contributors: paulommaeda
Requires at least: 6.6
Requires PHP: 8.0
Stable tag: 1.6.0
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
* Recebimentos identificam conta de destino, atualizam saldo e respeitam confirmação.
* Gastos, contas, recebimentos, cartões, faturas e dashboard privados por usuário.
* Abastecimentos novos geram gastos automaticamente para quem registrou.
* Crédito à vista ou em até 120 parcelas, distribuídas por fechamento/vencimento.
* Importação de fatura CSV/OFX e PDF pelo Gemini, com conferência e parcelas futuras.
* Pagamentos parciais de fatura reduzem o saldo da conta sem duplicar o gasto.

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
Veja docs/FINANCEIRO.md e docs/GASTOS.md para funcionamento e limites.

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
