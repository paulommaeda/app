# Telegram: abastecimentos por áudio e texto

Disponível a partir da versão 1.2.0. A integração fica desativada até o responsável
pelo portal configurar as credenciais e ativar a conexão. Cada pessoa vincula seu
próprio Telegram à conta WordPress; não existe vínculo por nome, telefone ou e-mail
informado na conversa.

## Configuração do administrador

1. No Telegram, abra [@BotFather](https://t.me/BotFather), envie `/newbot` e crie
   um bot exclusivo para o portal. Guarde o token fornecido.
2. No WordPress, abra **Portal de Serviços → Telegram**.
3. Informe o token e a chave de um projeto da API da OpenAI com faturamento/cota
   disponível. A chave é central: usuários do portal não precisam de chaves próprias.
4. Salve as configurações e clique em **Ativar conexão**. O WordPress precisa
   ter endereço público HTTPS e permitir requisições de entrada do Telegram.
5. Abra o portal com uma conta de cliente, entre em **Minha conta → Telegram**
   e faça o vínculo para testar um abastecimento.

As credenciais são guardadas criptografadas no banco, sem autoload, e não são
exibidas novamente no formulário. Deixar um campo vazio mantém a credencial.
Alterar as chaves de segurança/salts do WordPress exige informar as credenciais
novamente. Opcionalmente, defina `PDS_TELEGRAM_BOT_TOKEN` e `PDS_OPENAI_API_KEY`
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
`api.telegram.org` e `api.openai.com` e requisições internas ao próprio WordPress.

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

Padrões desta versão: `gpt-transcribe` para áudio e `gpt-4.1-mini` para interpretação
com saída estruturada. Os modelos podem ser alterados no painel, conforme a
disponibilidade do projeto da API. Não existe chamada real à OpenAI apenas por
salvar as credenciais ou ativar a conexão com o bot.

Um áudio usa transcrição e interpretação; uma mensagem de texto usa interpretação.
O consumo é feito na chave de API configurada, conforme a cobrança do provedor.
O limite inicial é de 30 mensagens de reconhecimento por usuário/dia, áudios de até
120 segundos e tamanho máximo de 5 MB. Limites diário e de duração são configuráveis.
Comandos e confirmações não fazem reconhecimento. Contas não vinculadas ou sem
acesso ao serviço não podem disparar reconhecimento pago.

## Dados, duplicações e privacidade

O áudio é baixado para processamento em memória e não é publicado nem salvo na
biblioteca de mídia. O conteúdo necessário é enviado ao Telegram e à OpenAI,
segundo suas respectivas políticas. O plugin pede consentimento ao gerar o vínculo.
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
verificando `pds_user_can_access_service('financeiro')` e o proprietário dos dados.
Um retorno `null` mantém o fluxo de combustível. O vínculo Telegram/WordPress
pode ser reutilizado; compartilhar um carro não concede acesso a finanças de terceiros.

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
