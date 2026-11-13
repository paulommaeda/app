# Validação da versão 1.4.0

Validação local em 12 de novembro de 2026 com WordPress 7.1.4, PHP 8.4.24,
MariaDB 11.8.6 e Chromium Headless 150.

Foram aprovadas **452 verificações**: 71 de integração do app, 57 de importação
e metadados de atualização, 81 de integração com o Telegram, 49 de integração
com o Gemini, 28 de navegação do app, oito de importação pela tela administrativa,
27 do Telegram/Gemini pela interface, 62 de gastos pessoais, 31 de gastos pelo
Telegram, 24 de gastos pelo navegador,
seis com links permanentes padrão e oito de atualização nativa do WordPress
com instalação real do pacote ZIP.
Os arquivos PHP passaram pela verificação de sintaxe e o JavaScript passou
por `node --check`.

## Principais cenários

* Ativação, criação de tabelas, página inicial e atualização idempotente do esquema.
* Registro nativo de contas sem aceitar elevação de privilégios enviada pelo cliente.
* Login de usuário WordPress já existente e encerramento da sessão.
* Validação de token de cadastro e limitação de tentativas no servidor.
* Recuperação de senha com chave nativa de uso único.
* Veículos e registros privados, incluindo tentativa de acesso direto por outro ID.
* Compartilhamento com outra conta, distinção entre proprietário e autor do registro
  e bloqueio imediato de consultas depois da revogação do compartilhamento.
* Cadastro, edição, exclusão e confirmação/cancelamento de exclusão pela interface.
* Consumo entre tanques completos com abastecimento parcial entre os dois.
* Média ponderada, custo/km, gastos e descontos, com valores conhecidos.
* Relatório com referência anterior ao início do período selecionado.
* Rejeição de datas inválidas/futuras, números negativos e quilometragem inconsistente.
* Paginação de histórico, exportação CSV e proteção contra fórmulas em texto do usuário.
* Bloqueio por serviço, suspensão por conta e habilitação global do módulo.
* Registro de um serviço independente com a mesma conta autenticada.
* Eventos de criação, atualização e exclusão com chave estável para integração.
* Exportação e apagamento pelas ferramentas de privacidade do WordPress.
* Redirecionamento de cliente do portal ao tentar acessar o wp-admin.
* Atualização de nome pelo front-end e proteção de alterações sensíveis por senha.
* Configurações administrativas e cabeçalhos que impedem cache da página do portal.
* Cadastro, dashboard, histórico, relatórios e exportação com links permanentes padrão.
* Revisão visual de login, portal, dashboard e relatórios; larguras de 320, 390, 768 e
  1440 pixels, sem transbordamento horizontal nas telas verificadas.

Não foram incluídos dados de teste, credenciais, WordPress, banco de validação,
bibliotecas de desenvolvimento ou capturas de tela no pacote de instalação.

## Importação e atualização

* Upload SQL pela tela administrativa, prévia sem gravações e processamento AJAX por etapas.
* Leitura das tabelas no mesmo banco e alternativa JSON com a mesma validação.
* Conta WordPress criada com o nome e e-mail antigos; e-mail existente vinculado sem sobrescrever senha, nome ou papel.
* Senha bcrypt original validada pelo login nativo e hash atualizado pelo WordPress após o primeiro login.
* Administrador antigo importado como cliente; hash incompatível sinalizado para recuperação de senha.
* Preservação de três casas em preços/litros, valores pagos, autoria e quilometragens repetidas.
* Compartilhamentos aceitos e pendentes preservados com permissões corretas.
* Repetição idempotente sem duplicar, sobrescrever edições ou recriar registros excluídos.
* Rejeição de SQL com expressões e de prefixos inválidos; comandos DDL/UPDATE presentes no backup não são executados.
* Migração do índice da versão 1.0, preservando os abastecimentos existentes.
* Atualização reconhecida no mecanismo nativo do WordPress; mesma versão não oferecida novamente.
* Download com integridade SHA-256, incluindo rejeição de arquivo corrompido.
* Instalação real pelo Plugin_Upgrader no modo de atualização automática, mantendo pasta, ativação, usuários, veículos, registros, mapas, vínculos, fila, rascunhos e configurações do Telegram.

## Telegram e reconhecimento de áudio

* Configuração administrativa, proteção das credenciais armazenadas e webhook autenticado com HTTPS.
* Vínculo de uso único à conta WordPress, consentimento, expiração e rejeição de grupos ou identidade privada inconsistente.
* Seleção de veículo, isolamento entre usuários e bloqueio após suspensão ou revogação de acesso, inclusive durante o reconhecimento.
* Áudio OGG multipart, transcrição em português e extração estruturada de dados; texto e áudio complementar para campos ausentes.
* Gravação automática de dados completos, confirmação opcional e rejeição de botões de rascunhos antigos.
* Ausência de suposições sobre veículo desconhecido, quilometragem, combustível, tanque cheio, preço e descontos.
* Autenticação do processamento assíncrono, limites por conta e deduplicação de entregas e gravações.
* Retentativa de notificação sem repetir reconhecimento pago ou criar outro abastecimento.
* Exportação e apagamento de dados pessoais, desvinculação e desativação administrativa.
* Navegação real no navegador para configurar, vincular, salvar por áudio, confirmar e desvincular; tela móvel sem transbordamento horizontal.

As respostas HTTP de **Telegram, Gemini e OpenAI foram simuladas** nos testes locais,
sem usar credenciais reais ou enviar áudio a esses provedores. O teste com um
áudio real deve ser feito no site HTTPS após cadastrar as credenciais e ativar
a conexão, conforme `TELEGRAM.md`. Os testes de banco, WordPress, navegação e
instalação do ZIP usam os componentes reais do ambiente de validação.

## Gemini e troca de provedor

* Reconhecimento de áudio OGG e texto com uma solicitação ao Gemini, sem chamada à OpenAI ou envio do áudio a um endereço público.
* Chave criptografada no banco e enviada somente no cabeçalho ao endereço fixo do provedor; redirecionamentos desabilitados.
* Saída JSON validada pelo mesmo esquema e regras do app, rejeitando respostas incompletas, truncadas, bloqueadas ou com tipos inválidos.
* Migração real da tabela da versão 1.2, preservando Telegram e veículo padrão e registrando o consentimento original para OpenAI.
* Troca de provedor sem desfazer o vínculo; reconhecimento bloqueado antes de consentimento renovado, com autorização pela conta nativa no portal.
* Criação de abastecimento, complemento por áudio, confirmação, duplicações e histórico com Gemini.
* Distinção entre chave, permissão, cota, modelo, rede, indisponibilidade e solicitação recusada; nenhuma alternativa automática para provedor pago.
* Diagnóstico administrativo sem resposta bruta da API, credenciais, texto ou áudio; reconhecimento válido remove o diagnóstico resolvido.
* Teste administrativo com o provedor selecionado, sem criar abastecimento; usuário sem permissão não pode executar a ação.
* Tela móvel e configuração administrativa verificadas no navegador, incluindo consentimento após trocar de OpenAI para Gemini e aviso da faixa gratuita.

## Meus Gastos e integração dos serviços

* Cadastro, edição, exclusão, paginação, busca e filtros de gastos em contas WordPress reais.
* Valores brasileiros com centavos, rejeição de valores inválidos e agregação decimal conhecida (R$ 0,10 + R$ 0,20 = R$ 0,30).
* Totais, média diária, maior gasto, percentuais, evolução, pagamento e comparação com período anterior verificados com valores conhecidos.
* Categorias individuais, criação, cores seguras, renomeação, arquivamento e reativação sem perda de histórico.
* Rejeição de acesso direto a IDs de despesas/categorias de outra conta, inclusive por administrador na API do app.
* Bloqueio por serviço e suspensão, filtros SQL preparados e pesquisa literal de caracteres especiais.
* Exportação/apagamento pela privacidade WordPress, incluindo mais de 100 despesas, sem afetar outra conta.
* Registro de despesa por áudio e texto no mesmo bot, com o modelo Gemini Flash-Lite configurado e uma interpretação por mensagem.
* Data padrão da mensagem, categorias próprias, esclarecimento de valor/categoria, complementos por áudio e confirmação opcional.
* Identificação de serviço no reprocessamento da fila, deduplicação, falha de notificação, bloqueio e desvinculação durante reconhecimento.
* Interface do serviço revisada em 320, 390, 768 e 1440 pixels; edição, exclusão, categorias, gráficos e CSV exercitados no navegador.
* Despesa recebida por webhook HTTP aparece no dashboard da mesma conta; outra conta permanece sem acesso aos registros.
* Atualização nativa do pacote preserva gastos, categorias e pendências financeiras, além dos dados e configurações anteriores.

A interpretação linguística das despesas e a disponibilidade/cota do provedor
precisam ser verificadas com a chave do site e áudios reais. Os testes locais
usam respostas controladas de IA, inclusive para o modelo Flash-Lite.

## Controle financeiro — 1.5.0

Validação em WordPress 7.1.4, PHP 8.4 e MariaDB 11.8. Foram exercitados:

* 89 verificações de integração financeira: contas, saldos, receitas realizadas/pendentes, cartões, divisão exata de centavos, faturas, pagamentos parciais e ajustes.
* Limites de fechamento, compra no próprio dia, virada de mês/ano e fevereiro de ano comum/bissexto; preservação de datas existentes e fechamento corrigido de um ciclo.
* Totais por compra separados do fluxo de caixa; pagar fatura não duplica despesa.
* Espelho de abastecimento criado, alterado e excluído atomicamente; rejeição reverte a transação. Compartilhamento de veículo mantém dados bancários/cartões privados.
* CSV/OFX reais, sinais de crédito/estorno, parcela 3/10, projeção das restantes, reconciliação de parcela futura com valor corrigido, repetição idempotente e duas compras idênticas preservadas.
* 21 verificações de Telegram/PDF: cartão ausente, múltiplos cartões, modo à vista/parcelado, correção de pendência, conta de saída, crédito em abastecimento e complemento financeiro de abastecimento.
* 31 verificações pelo navegador: formulários completos, fatura paga parcialmente, mudança de parcelado para à vista, recebimentos, abastecimento integrado, upload multipart CSV/OFX/PDF, revisão editável, descarte e isolamento.
* Telas conferidas em 320, 390, 768 e 1440 pixels; sem erros JavaScript não capturados.
* Exportação/apagamento WordPress inclui o razão financeiro; apagar proprietário do veículo preserva despesa particular de outro participante, separada do registro excluído.
* Atualizador nativo verifica o pacote, instala na pasta canônica e compara todos os registros financeiros antes/depois, além de usuários, histórico e configurações anteriores.

As respostas de Telegram e Gemini são controladas nos testes. O upload PDF é
exercitado pelo navegador, mas o reconhecimento de documentos bancários reais e
o áudio real dependem da chave/modelo/cota da hospedagem. Não houve envio de
faturas ou mensagens reais aos provedores durante os testes locais.

## Recebimentos no Telegram — 1.6.0

* 56 verificações de integração da entrada por texto/áudio, incluindo o exemplo “recebi 100 reais na conta C6 pessoa jurídica”, descrição neutra e centavos exatos.
* Distinção entre contas C6 PF/PJ, aliases pessoa física/jurídica, nomes ambíguos, conta ausente, contas de outro usuário, contas arquivadas e valor/data incertos.
* Complemento de mensagens, data da mensagem no fuso do WordPress, data explícita, rejeição de valores inválidos/futuros e de múltiplos lançamentos misturados.
* Preferência de confirmação individual/global, confirmação textual e por botão, correção de resumo, token antigo/forjado, cancelamento e identidade Telegram de terceiros.
* Idempotência do webhook, origem única do recebimento e retomada da fila/notificação sem duplicar crédito, despesa ou reconhecimento.
* API nativa ignora origem/identidade fornecidas pelo cliente; leitura oculta referências internas; outro usuário não edita/apaga os dados pessoais.
* Bloqueio de serviço e conta arquivada durante reconhecimento, desvinculação, expiração, exportação/apagamento de privacidade e suporte ao esquema na alternativa OpenAI.
* 16 verificações pelo navegador, com webhook HTTP real e respostas externas simuladas: saldo, fluxo de caixa, histórico, voz, complementos, repetição, confirmação e cancelamento.
* Interface mobile sem transbordamento e sem erros JavaScript; usuário WordPress diferente não altera o recebimento, inclusive se for administrador.

Gemini, OpenAI e Telegram usam respostas controladas nos testes; não foram
utilizados credenciais, recebimentos ou áudios reais de produção. A atualização
mantém o modelo, as chaves, os vínculos, as preferências e os dados existentes.

## Contas e recebimentos previstos — 1.7.0

* 53 verificações de integração: conta única/mensal, valor a definir, previsão separada do saldo, baixa com valor/data reais, repetição idempotente, reversão atômica e restrições de faturas já pagas.
* Recorrências finitas e sem término, geração inicial de 12 meses, dia âncora 31 em meses curtos, edição somente de previsões pendentes e encerramento sem apagar movimentos realizados.
* Recebimentos futuros e mensais permanecem pending, inclusive após geração/cron; só a confirmação manual altera o saldo. Cancelamento de previsões não é realização.
* Fatura mensal automática sem compra aparece sem saldo a pagar; compras e parcelas atualizam seu valor, pagamentos parciais atualizam a mesma fatura e nenhuma segunda despesa é criada.
* 27 verificações Telegram: texto/áudio, cadastro único/mensal, valor indefinido, complemento de vencimento, conta/categoria própria, confirmação, repetição de webhook/fila/notificação e previsões de recebimento sem baixa.
* 22 verificações pelo navegador: formulários, baixa/reversão/cancelamento, recorrências, receita futura mensal, recebimento manual e pagamento de fatura pela área de contas.
* Propriedade verificada para contas, previsões, recorrências e baixas; falhas de pagamento revertem status e gasto. Privacidade remove definições e ocorrências para impedir regeneração.
* Interfaces em 320, 390, 768 e 1440 pixels, sem transbordamento nem exceções JavaScript. Atualização nativa compara também previsões, recorrências e pendências Telegram.

As respostas dos provedores externos permanecem simuladas nos testes. O cadastro
pelo bot é exercitado com a validação e o razão financeiro reais do WordPress;
nenhum pagamento ou recebimento bancário é executado pelo plugin.

## Transferências entre contas — 1.8.0

Validação em 13 de novembro de 2026. A suíte completa reúne **839 verificações**,
com o mesmo ambiente WordPress/PHP/MariaDB/Chromium descrito acima.

* 48 verificações de integração de transferências: centavos e valores localizados,
  conservação dos saldos, exclusão dos totais de gastos/receitas, saldos históricos,
  contas próprias e ativas, permissões de serviço e datas dos saldos iniciais.
* Repetição idempotente, conflito ao reutilizar chave com outros dados, falha de
  gravação sem efeito parcial, estorno repetido e reenvio de pedido já estornado.
* Histórico nos dois sentidos, filtros por conta/mês/situação e paginação de 51
  movimentos de um centavo, além de exportação/apagamento de privacidade.
* 24 verificações no navegador: cadastro real, atalhos nas contas, conta de
  destino diferente da origem, saldos, erros do formulário, confirmação de
  estorno, falha de rede depois da gravação e repetição sem duplicação.
* Interface em 320, 390, 768 e 1440 pixels, sem transbordamento ou erros JavaScript.
* Regressão dos serviços anteriores e atualização nativa preservando também a
  tabela de transferências, os usuários e todas as configurações existentes.

Nenhuma transferência bancária real é executada; os testes usam contas fictícias
no banco local. A chave de IA, os vínculos Telegram e os dados de produção não
são utilizados durante essa validação.
