# Telegram: abastecimentos por áudio e texto

Disponível a partir da versão 1.2.0, com Gemini na versão 1.3.0. A integração fica
desativada até o responsável
pelo portal configurar as credenciais e ativar a conexão. Cada pessoa vincula seu
próprio Telegram à conta WordPress; não existe vínculo por nome, telefone ou e-mail
informado na conversa.

## Configuração do administrador

1. No Telegram, abra [@BotFather](https://t.me/BotFather), envie `/newbot` e crie
   um bot exclusivo para o portal. Guarde o token fornecido.
2. No WordPress, abra **Portal de Serviços → Telegram**.
3. Selecione **Gemini (Google)** e informe a chave criada no
   [Google AI Studio](https://aistudio.google.com/apikey). Para usar a faixa
   gratuita, mantenha o projeto no nível gratuito e não habilite faturamento.
   A chave é central: usuários do portal não precisam de chaves próprias.
   OpenAI continua como alternativa, exigindo créditos/cota da API.
4. Salve e use **Testar reconhecimento** para conferir a chave e o modelo.
   O teste envia apenas um texto de teste, consome uma solicitação da cota e
   não cria abastecimento. Depois clique em **Ativar conexão**. O WordPress precisa
   ter endereço público HTTPS e permitir requisições de entrada do Telegram.
5. Abra o portal com uma conta de cliente, entre em **Minha conta → Telegram**
   e faça o vínculo para testar um abastecimento.

As credenciais são guardadas criptografadas no banco, sem autoload, e não são
exibidas novamente no formulário. Deixar um campo vazio mantém a credencial.
Alterar as chaves de segurança/salts do WordPress exige informar as credenciais
novamente. Opcionalmente, defina `PDS_TELEGRAM_BOT_TOKEN`, `PDS_GEMINI_API_KEY`
e/ou `PDS_OPENAI_API_KEY`
no `wp-config.php`; nesse caso os campos correspondentes ficam bloqueados no painel.
Não coloque tokens ou chaves no GitHub.

Um bot só possui uma conexão de webhook ativa. Usar um bot exclusivo evita
substituir a conexão de outro sistema. O botão Ativar conexão registra o webhook
deste portal. O botão Desativar recebimento remove a conexão e impede gravações
pelo Telegram, preservando os dados do app.

## Fila e agendamento

O webhook autentica a entrega e coloca a mensagem em uma fila. Ele responde ao
Telegram antes de baixar/transcrever o áudio. O portal tenta processar a fila por
uma chamada interna assinada e mantém um agendamento do WordPress como alternativa.

Para respostas regulares em um site com pouco acesso, configure o cron da hospedagem
para executar o agendamento WordPress a cada minuto. Se `DISABLE_WP_CRON` estiver
ativado, o cron externo é necessário. O administrador pode executar uma etapa
manualmente com **Processar mensagens pendentes** e conferir os contadores da fila.
Cada etapa processa até duas mensagens; solicitações simultâneas não processam a
mesma etapa em paralelo. A hospedagem deve permitir conexões HTTPS de saída para
`api.telegram.org` e ao provedor escolhido (`generativelanguage.googleapis.com`
para Gemini ou `api.openai.com` para OpenAI), além de requisições internas ao
próprio WordPress.

## Vínculo do usuário

1. Faça login no portal e abra **Minha conta → Telegram**.
2. Confirme o uso das mensagens para reconhecimento e clique em **Vincular meu Telegram**.
3. Abra o link e toque em **Iniciar** na conversa com o bot. Se necessário, envie
   o comando exibido pelo portal.
4. Volte ao portal e toque em **Já vinculei, atualizar**.
5. Escolha um veículo padrão e se deseja conferir o resumo antes de salvar.

O código é aleatório, vale por dez minutos e só pode ser utilizado uma vez.
Somente seu hash fica na tabela de vínculos. Um Telegram não pode ser vinculado
simultaneamente a duas contas WordPress. Para trocar, desvincule primeiro.
Não envie a senha do WordPress ao bot. Conversas em grupos são ignoradas.

## Enviar um abastecimento

Exemplo de mensagem falada ou escrita:

> Hoje abasteci o HB20 com gasolina. Foram 30 litros a seis reais e vinte
> centavos. Paguei 180 reais, o odômetro está em 45.200 e completei o tanque.

| Campo | Como é tratado |
| --- | --- |
| Veículo | Nome/placa mencionado, veículo padrão autorizado ou único veículo acessível |
| Data | Data mencionada; hoje/ontem usa a data da mensagem no fuso do WordPress |
| Data não mencionada | Data da mensagem, sem exigir que o usuário fale hoje |
| Odômetro | Deve ser informado; não é deduzido do histórico |
| Combustível | Gasolina, etanol/álcool ou diesel, informado na mensagem |
| Litros e preço de tabela | Extraídos do que a pessoa falou |
| Valor pago | Valor real informado, preservando os descontos |
| Tanque completo/parcial | Deve ser informado; não é presumido |

Se faltar ou estiver ambíguo algum campo obrigatório, o bot pergunta. A pessoa pode
responder apenas o complemento por áudio ou texto. A pendência expira em duas horas.
Uma nova mensagem enquanto houver pendência é tratada como complemento; use
`/cancelar` ou `/novo` para descartar e começar outro abastecimento.

Preço de tabela e valor pago não são considerados iguais por padrão. O cálculo
de um campo ausente usando preço × litros só é feito se a pessoa disser
explicitamente que não houve desconto/acréscimo. Valores contraditórios são
devolvidos para esclarecimento.

Sem confirmação habilitada, dados completos e válidos são gravados automaticamente
e o bot envia o resumo com os valores que ficaram no app. Com confirmação habilitada,
ele mostra os botões **Salvar abastecimento** e **Cancelar**. O administrador pode
exigir confirmação para todos; o usuário também pode habilitá-la para si.
Erros de reconhecimento podem ser corrigidos pelo histórico do app.

## Comandos

| Comando | Ação |
| --- | --- |
| `/ajuda` | Exibe um exemplo e os comandos |
| `/veiculos` | Lista os veículos autorizados da conta |
| `/veiculo ID` | Define o veículo padrão; também pode completar uma pendência |
| `/confirmar` ou `/salvar` | Salva um resumo completo pendente |
| `/cancelar` ou `/novo` | Descarta a pendência atual |
| `/desvincular` | Remove o vínculo entre Telegram e WordPress |

O aplicativo verifica novamente o acesso ao veículo no momento da gravação.
Compartilhamento revogado não pode ser usado como veículo padrão e não é trocado
silenciosamente por outro carro. O compartilhamento continua restrito ao veículo.
Bloqueios e suspensão do portal também valem no Telegram.

## Modelos, consumo e limites

O padrão para novas instalações é **Gemini (Google)**, modelo `gemini-3.8-flash`,
com entrada de áudio e saída estruturada. Um áudio ou texto usa uma solicitação
para reconhecer os campos do abastecimento, sem passar pela OpenAI. O áudio é
anexado em memória, dentro do limite de 5 MB; não é publicado nem enviado a uma
API separada de arquivos.

O Google oferece faixa gratuita para esse modelo, sujeita à elegibilidade e às
cotas do projeto. Confira as cotas no AI Studio: não há promessa de uso ilimitado
ou gratuidade permanente. Um projeto com faturamento ativado pode gerar cobrança.
Na faixa gratuita, o Google pode usar o conteúdo para melhorar seus produtos,
conforme os [preços e condições](https://ai.google.dev/gemini-api/docs/pricing).
A assinatura do aplicativo Gemini não é necessária para gerar uma chave da API.

Instalações anteriores mantêm OpenAI até o administrador selecionar Gemini e
salvar sua chave. Os modelos OpenAI continuam `gpt-transcribe` para transcrição
e `gpt-4.1-mini` para os campos: um áudio usa duas solicitações; texto usa uma.
Os nomes de modelos podem ser alterados no painel conforme o acesso do projeto.

A troca de provedor não remove o bot, contas, vínculos, veículos ou histórico.
Cada usuário já vinculado abre **Minha conta → Telegram**, confirma o uso pelo
novo provedor e salva as preferências, sem refazer o pareamento. Até essa
confirmação, nenhum conteúdo da conta é enviado ao novo provedor.

O plugin usa somente o provedor selecionado. Ao atingir a cota, explica a
limitação e mantém o cadastro manual pelo portal. Não ativa faturamento nem usa
outro provedor como alternativa automática. Salvar credenciais ou ativar o bot
não chama uma IA; o botão de teste e mensagens com reconhecimento fazem chamadas.

O limite inicial é de 30 mensagens de reconhecimento por usuário/dia, áudios de até
120 segundos e tamanho máximo de 5 MB. Limites diário e de duração são configuráveis.
Comandos e confirmações não fazem reconhecimento. Contas não vinculadas ou sem
acesso ao serviço não podem disparar reconhecimento pago.

## Dados, duplicações e privacidade

O áudio é baixado para processamento em memória e não é publicado nem salvo na
biblioteca de mídia. O conteúdo necessário é enviado ao Telegram e ao provedor selecionado,
segundo suas respectivas políticas. O portal mostra o provedor e as condições
da faixa gratuita. O plugin pede consentimento ao gerar o vínculo e ao trocar
o provedor.
Ele não envia senhas WordPress, e-mails de outras contas ou a lista de usuários à IA.

Mensagens na fila e campos de pendências ficam em tabelas internas do WordPress,
sem endpoint público de consulta. Após processar, o conteúdo da mensagem é removido;
falhas temporárias mantêm apenas a resposta necessária a uma nova tentativa de entrega.
A limpeza diária expira conteúdos antigos e remove identificadores mínimos de
entrega após 90 dias. A limpeza depende do agendamento funcionando.

Os IDs de entrega evitam processar duas vezes uma repetição do webhook. Cada
abastecimento também recebe uma origem interna única para não duplicar uma gravação
se o processamento for retomado após uma falha. Repetir a entrega da resposta
não executa novamente o reconhecimento. Áudios reenviados como mensagens novas
também passam pela validação de quilometragem do aplicativo.

Desvincular impede novos lançamentos pelo bot. Exportar/apagar dados pelas
ferramentas de privacidade do WordPress inclui o vínculo e remove fila/pendências.
Abastecimentos já gravados seguem as regras normais do app.

## Extensão para próximos serviços

O filtro `pds_telegram_reply($reply, $payload, $link)` é executado após identificar
a conta nativa e somente em conversa privada. Um próximo plugin financeiro pode
retornar uma resposta nesse filtro e implementar seu próprio reconhecimento,
verificando `pds_user_can_access_service('financeiro')`, o proprietário dos dados
e se `consent_provider` do vínculo corresponde ao `ai_provider` configurado.
Um retorno `null` mantém o fluxo de combustível. O vínculo Telegram/WordPress
pode ser reutilizado; compartilhar um carro não concede acesso a finanças de terceiros.

## Diagnóstico de falhas

O painel mostra a última falha de reconhecimento com provedor, etapa áudio/texto,
status HTTP, categoria e horário UTC. O conteúdo é uma classificação interna do
plugin: não armazena resposta bruta da API, chave, texto, transcrição ou áudio.
Um reconhecimento válido ou teste bem-sucedido remove o diagnóstico resolvido.

* **Chave recusada/expirada:** substitua a chave no campo do provedor selecionado.
* **Permissão:** confira a API habilitada, as restrições da chave e o acesso ao modelo.
* **Cota/limite de requisições:** confira os limites do projeto no AI Studio e aguarde
  a renovação aplicável; o portal manual permanece disponível.
* **Sem créditos/limite financeiro (OpenAI):** confira o faturamento da API.
* **Modelo indisponível:** selecione um modelo de áudio/JSON disponível no projeto.
* **Rede:** verifique conexões HTTPS de saída, certificados e firewall da hospedagem.
* **Formato recusado/resposta incompleta:** confira o modelo e teste uma descrição
  curta de um único abastecimento; nenhuma resposta parcial é gravada.

## Verificação antes de uso real

Os testes automatizados usam respostas controladas dos serviços externos para
conferir autenticação, formatos, permissões, fila e gravação sem consumir créditos.
Depois de configurar seu bot e projeto, teste um áudio real e confira os números
no histórico. A qualidade de reconhecimento depende do áudio e do modelo configurado.

Referências de implementação:

- [Telegram Bot API](https://core.telegram.org/bots/api)
- [Transcrição de arquivos](https://developers.openai.com/api/docs/guides/transcription)
- [Referência de transcrição](https://developers.openai.com/api/reference/resources/audio/subresources/transcriptions/methods/create)
- [Saída estruturada](https://developers.openai.com/api/docs/guides/structured-outputs)

- [Gemini: compreensão de áudio](https://ai.google.dev/gemini-api/docs/audio)
- [Gemini: saída estruturada](https://ai.google.dev/gemini-api/docs/structured-output)
- [Gemini: preços e condições](https://ai.google.dev/gemini-api/docs/pricing)
- [Gemini: criar chave](https://aistudio.google.com/apikey)
