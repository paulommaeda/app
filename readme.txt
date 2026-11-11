=== Portal de Serviços — Meu Combustível ===
Contributors: paulommaeda
Requires at least: 6.6
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Portal de serviços com usuários WordPress e controle de combustível individual.

== Description ==

Uma única instalação reúne cadastro, login, recuperação de senha, conta pessoal,
catálogo de serviços e o primeiro módulo: Meu Combustível.

* Cadastro com papel restrito de cliente, usando wp_users e autenticação nativa.
* Contas WordPress existentes entram com seu e-mail e sua senha atual.
* Veículos privados por proprietário; compartilhamento explícito com outras contas.
* Abastecimentos com edição, exclusão, gasolina, etanol e diesel, preço de tabela,
  litros, valor pago, descontos, observações e tanques completos/parciais.
* Dashboard, evolução do custo/km, relatórios por período e exportação CSV.
* Consumo ponderado entre tanques completos, incluindo abastecimentos parciais.
* Cadastro público configurável e bloqueio de serviços por usuário.
* Layout próprio responsivo; possibilidade de exibir dentro do tema.
* Registro público de módulos e eventos de integração para o futuro financeiro.
* API com autenticação, nonces e verificação de acesso a cada recurso.
* Exportação/apagamento pelas ferramentas de privacidade do WordPress.
* Sem JavaScript de CDN ou banco separado; atualizações consultadas no GitHub.
* Importação do banco antigo por SQL/JSON ou leitura das tabelas existentes.

== Installation ==

1. No WordPress, acesse Plugins > Adicionar plugin > Enviar plugin.
2. Envie portal-de-servicos.zip e clique em Instalar agora e Ativar.
3. A página Portal de Serviços é criada automaticamente com [portal_servicos].
4. Acesse o menu Portal de Serviços para abrir a página e ajustar as opções.
5. Configure o envio de e-mail do WordPress para recuperação de senha.
6. Exclua a página do portal e /wp-json/pds/v1/ do cache da hospedagem/CDN.

Use HTTPS no portal publicado. Não é necessário importar database.sql nem
editar credenciais de banco: o plugin usa a conexão do próprio WordPress.

== Shortcodes ==

[portal_servicos] - catálogo de serviços, login e conta pessoal.
[portal_combustivel] - abre diretamente o serviço Meu Combustível.

Insira em páginas publicadas. Mantenha apenas um desses shortcodes em cada página.
Se usar uma página existente como principal, selecione-a nas configurações e
insira o shortcode nela. A ativação não muda a página inicial do site.

== Frequently Asked Questions ==

= É preciso outro cadastro para o próximo plugin? =
Não. Os usuários são contas nativas do WordPress. O próximo plugin usa o mesmo
ID, sessão, e-mail e senha e se registra no catálogo pelo núcleo deste plugin.
Consulte docs/INTEGRACAO.md.

= Este pacote já contém um sistema financeiro ou cobrança de assinaturas? =
Não. Entrega o portal e o serviço de combustível. O financeiro será outro
plugin. Cobrança, planos pagos e assinatura recorrente não estão implementados.
Há pontos de integração para regras de acesso e despesas de abastecimento.

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

= 1.1.0 =
* Atualização nativa pelo WordPress a partir do GitHub, com integridade do ZIP.
* Importação SQL/JSON ou do banco antigo, com contas nativas WordPress.
* Preservação de senhas compatíveis, vínculos, valores e registros duplicados em KM.
* Processamento retomável e idempotente, sem sobrescrever alterações do novo app.

= 1.0.0 =
* Conversão completa para WordPress, portal modular e serviço Meu Combustível.
