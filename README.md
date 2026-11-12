# Portal de Serviços — Meu Combustível

Plugin WordPress de Paulo Maeda para um portal de serviços com conta única.
O primeiro serviço controla veículos, abastecimentos, gastos, consumo e descontos.

**[Baixar o plugin instalável](https://raw.githubusercontent.com/paulommaeda/app/main/releases/portal-de-servicos-1.3.0.zip)**

## Instalação

1. Baixe o ZIP instalável acima.
2. No WordPress, acesse **Plugins → Adicionar plugin → Enviar plugin**.
3. Instale e ative. A página **Portal de Serviços** será criada automaticamente.
4. Abra **Portal de Serviços** no painel para configurar nome, cadastro e serviços.

Requisitos: WordPress 6.6+, PHP 8.0+ e banco MySQL/MariaDB do WordPress.
O shortcode principal é `[portal_servicos]`; `[portal_combustivel]` abre o serviço diretamente.

## Importar o aplicativo antigo

Acesse **Portal de Serviços → Importar app antigo**. Você pode enviar o backup
**SQL/JSON com os dados** ou ler as tabelas antigas que estiverem no mesmo banco
do WordPress. Confira o resumo e clique em **Iniciar importação**.

Os usuários antigos são criados em `wp_users`. Se o e-mail já existir, a conta
é vinculada sem alterar senha, nome ou permissões. Senhas bcrypt compatíveis
do app original continuam funcionando. Novas contas recebem o papel restrito
**Cliente do portal**, inclusive os antigos administradores do app.

Veículos, autores de abastecimentos e compartilhamentos são ligados aos novos
IDs. Repetir o mesmo banco com o mesmo identificador de origem não duplica os
registros nem sobrescreve edições feitas no app novo. Os dados antigos não são alterados.

O arquivo `database.sql` de estrutura, sozinho, não contém os registros dos usuários.
Veja [o procedimento de migração](docs/MIGRACAO.md).

## Atualizações pelo WordPress

O plugin consulta o arquivo público [`update.json`](update.json) deste repositório.
Quando uma versão nova é publicada, ela aparece em **Plugins** e **Painel →
Atualizações**. É possível ativar a atualização automática pelo controle nativo
do WordPress. O pacote baixado tem sua integridade SHA-256 conferida antes da instalação.

Se já instalou a versão 1.0.0, envie o ZIP 1.3.0 uma vez pelo WordPress e escolha
substituir a instalação atual. As próximas versões poderão ser atualizadas pelo painel.

Para publicar uma versão nova:

1. Aumente a versão em `portal-de-servicos.php` e `readme.txt`.
2. Atualize o changelog e execute `python3 tools/build_release.py`.
3. Publique o código, `update.json` e o novo ZIP em `releases/` na branch `main`.
4. Não substitua os arquivos ZIP de versões anteriores.

O script não contém tokens ou credenciais. A atualização não depende da API
autenticada do GitHub nem de serviços de assinatura pagos.

## Telegram por áudio e texto

Em **Portal de Serviços → Telegram**, escolha **Gemini (Google)**, informe a chave
criada no [Google AI Studio](https://aistudio.google.com/apikey), salve e use
**Testar reconhecimento**. O Gemini oferece faixa gratuita sujeita ao modelo
e às cotas do projeto. Para usar sem cobrança, mantenha o projeto no nível
gratuito, sem habilitar faturamento. Na faixa gratuita, o Google pode usar o
conteúdo para melhorar seus produtos. OpenAI continua como alternativa com
créditos próprios da API. Depois de configurar o token do bot, ative a conexão. Cada usuário faz o vínculo em **Minha conta → Telegram**, escolhe um
veículo padrão e envia áudio ou texto. Dados completos são salvos automaticamente;
dados ausentes geram perguntas. A confirmação antes de salvar é opcional.

Na troca de OpenAI para Gemini, salve as novas configurações e confirme o novo
provedor em Minha conta. O bot, as contas WordPress, os veículos e os dados são
preservados. O painel identifica falhas de chave, permissão, cota, modelo ou rede
sem guardar mensagens privadas nem credenciais nos diagnósticos.

O WordPress precisa usar HTTPS e ter o agendamento funcionando. A integração
fica desativada até configurar as chaves no site; nenhuma credencial acompanha
o código. Consulte [o manual do Telegram](docs/TELEGRAM.md).

## Próximos serviços

O futuro financeiro pode ser outro plugin, usando as mesmas contas e a mesma
sessão WordPress. O núcleo oferece catálogo, regras de acesso e eventos de
abastecimento com chave estável para integração de despesas.

- [Integração de novos serviços](docs/INTEGRACAO.md)
- [Migração de dados antigos](docs/MIGRACAO.md)
- [Validação](docs/VALIDACAO.md)
- [Manual e perguntas frequentes](readme.txt)

Configure o envio de e-mails do WordPress para recuperação de senha. Exclua a
página do portal e a API `/wp-json/pds/v1/` de caches públicos e de cache offline.
Use HTTPS na publicação. Nenhum backup SQL, credencial de banco ou dado de usuário
deve ser enviado ao repositório.

Licença: GPL-2.0-or-later.
