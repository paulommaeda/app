# Portal de Serviços

Plugin WordPress de Paulo Maeda para um portal de serviços com conta única.
Reúne Meu Combustível e Meu Financeiro: veículos, abastecimentos, gastos, contas, recebimentos, cartões e faturas.

**[Baixar o plugin instalável](https://raw.githubusercontent.com/paulommaeda/app/main/releases/portal-de-servicos-1.6.0.zip)**

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

Se já instalou a versão 1.0.0, envie o ZIP 1.6.0 uma vez pelo WordPress e escolha
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

## Meus Gastos (1.4.0)

O segundo serviço do portal registra gastos pessoais por conta WordPress:
valor em reais, descrição, data, categoria, pagamento e estabelecimento.
Há cadastro e edição pelo front-end, categorias com cores e arquivamento,
histórico paginado e CSV, além do dashboard com filtros, distribuição por
categoria, evolução, pagamentos e comparação com o período anterior.

O mesmo bot aceita **“gastei 35 reais no almoço hoje, no Pix”** por áudio ou texto.
Ele identifica o serviço em uma única interpretação, pergunta dados ausentes e
respeita a confirmação opcional. Usuários, histórico de combustível, modelo da IA
e vínculos existentes são preservados. Gastos pessoais pertencem somente à conta.
Consulte [o manual de Meus Gastos](docs/GASTOS.md).

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

## Meu Financeiro (1.5.0)

O serviço Meus Gastos evoluiu para **Meu Financeiro**, mantendo a rota `gastos`,
`[portal_gastos]`, categorias, despesas e usuários. Agora inclui contas com saldo
inicial, recebimentos realizados/a receber, cartões, faturas, parcelamento,
fluxo de caixa e importação de fatura. Novos abastecimentos geram despesas
automaticamente para quem os registra, com pagamento particular e sem duplicação.

Cadastre o fechamento e vencimento dos cartões. Compras de crédito à vista ou
parceladas são distribuídas pelas faturas; pagar uma fatura baixa o saldo da
conta sem gerar uma segunda despesa. CSV/OFX são lidos localmente. PDFs usam o
Gemini configurado, com autorização na tela e revisão dos dados extraídos.

Veja [FINANCEIRO.md](docs/FINANCEIRO.md) para implantação, regras de fechamento,
formato de importação, parcelas projetadas e limites.

## Recebimentos pelo Telegram (1.6.0)

Diga ou envie por áudio: **“Recebi 100 reais na conta C6 pessoa jurídica.”**
A conta de destino deve estar cadastrada em **Meu Financeiro → Contas**. O bot
registra a entrada, atualiza o saldo e o fluxo de caixa e mostra o resumo. Usa
o mesmo vínculo, provedor e preferência de confirmação já configurados.

`/contas` lista suas contas ativas; `/recebimentos` mostra ajuda. Se valor, data
ou conta estiverem incertos, o bot pergunta e aceita só o complemento. PF/PJ
são reconhecidos; nomes ambíguos nunca escolhem uma conta automaticamente.
Sem origem mencionada, a descrição é **Recebimento**. Previsões de entrada
continuam disponíveis no portal como **A receber**.
