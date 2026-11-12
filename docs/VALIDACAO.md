# Validação da versão 1.2.0

Validação local em 12 de novembro de 2026 com WordPress 7.1.4, PHP 8.4.24,
MariaDB 11.8.6 e Chromium Headless 150.

Foram aprovadas **275 verificações**: 71 de integração do app, 57 de importação
e metadados de atualização, 81 de integração com o Telegram, 28 de navegação do
app, oito de importação pela tela administrativa, 16 do Telegram pela interface,
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

As respostas HTTP de **Telegram e OpenAI foram simuladas** nos testes locais,
sem usar credenciais reais ou enviar áudio a esses provedores. O teste com um
áudio real deve ser feito no site HTTPS após cadastrar as credenciais e ativar
a conexão, conforme `TELEGRAM.md`. Os testes de banco, WordPress, navegação e
instalação do ZIP usam os componentes reais do ambiente de validação.
