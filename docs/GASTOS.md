# Meus Gastos

Serviço de gastos pessoais disponível no Portal de Serviços a partir da versão
1.4.0. Usa o mesmo cadastro, sessão WordPress e vínculo Telegram do portal.
O serviço não exige veículo nem uma nova conta.

## Atualizar e começar

1. Atualize o plugin para 1.4.0 pelo painel do WordPress ou envie o ZIP e escolha
   substituir a versão instalada. O esquema é atualizado automaticamente.
2. Entre no portal e abra **Meus Gastos** no menu ou no catálogo de serviços.
3. Use **Registrar gasto** ou envie um áudio/texto ao bot já vinculado.

As contas, veículos, abastecimentos, configuração do modelo da IA e vínculos do
Telegram existentes são preservados. Se sua chave/modelo já funciona para voz,
é usado também para gastos. Nenhuma chave vem no pacote.

## Registro manual

Cada gasto tem descrição, valor total em reais, data e categoria. Forma de
pagamento, estabelecimento e observações são opcionais. Valores aceitam `35,90`,
`1250.00` e `1.250,00`; não aceitam negativos, zero ou frações de centavo. A data
é a do gasto realizado, até hoje, no fuso do site.

O histórico permite corrigir ou excluir os próprios registros. A exclusão pede
confirmação e recalcula o dashboard. Um registro feito no Telegram pode ser
corrigido normalmente pelo portal.

## Categorias

A primeira abertura cria categorias individuais para a conta: Alimentação,
Mercado, Transporte, Moradia, Contas e serviços, Saúde, Educação, Lazer, Compras,
Assinaturas, Presentes e doações e Outros.

Em **Categorias**, crie, renomeie, escolha a cor, arquive e reative categorias.
Arquivar impede novos lançamentos nessa categoria, preservando os gastos e os
gráficos anteriores. É possível corrigir um registro antigo mantendo sua
categoria arquivada. Renomear atualiza o nome exibido no histórico. Há um limite
de 100 categorias por conta, incluindo arquivadas.

## Dashboard e histórico

Os filtros de início/fim, categoria, texto e forma de pagamento são os mesmos
para todas as informações exibidas:

* Total gasto, quantidade de registros e maior gasto.
* Média por dia do período escolhido, incluindo os dias sem lançamentos.
* Comparação com o intervalo de mesma duração imediatamente anterior, com as
  datas da comparação explícitas. Sem base anterior, não inventa uma variação.
* Distribuição por categoria, valores e percentuais sobre o total filtrado.
* Evolução diária em períodos de até 62 dias; mensal em períodos maiores.
* Distribuição por pagamento e últimos registros.

Escolha um período de até dois anos por consulta. O histórico é paginado e
exporta CSV com os mesmos filtros, até 10.000 registros por arquivo. O CSV usa
UTF-8, ponto e vírgula e valores em formato brasileiro. Campos de texto que
poderiam executar fórmulas em planilhas são neutralizados.

## Registrar pelo Telegram

Exemplos de áudio ou texto:

> Hoje gastei 35 reais e 90 centavos no almoço, paguei no Pix.

> Ontem paguei 120 reais na farmácia, na categoria Saúde, no débito.

> Gastei 89 reais e 90 centavos com internet hoje.

O bot identifica o serviço e usa uma única interpretação por mensagem. Um
abastecimento detalhado continua no Meu Combustível; um gasto pessoal vai para
Meus Gastos. A mesma mensagem não é gravada automaticamente nos dois serviços.
O campo de veículo padrão vale apenas para combustível.

* A data não mencionada usa a data da mensagem, no fuso do WordPress.
* Descrição e valor precisam estar claros. O bot pergunta pelos dados ausentes
  ou incertos e aceita o complemento por texto ou áudio.
* A IA pode sugerir uma das categorias ativas da própria conta de acordo com a
  finalidade. Sem categoria compatível, usa Outros, se estiver ativa.
* Uma categoria explicitamente mencionada e inexistente não é criada nem
  trocada silenciosamente: o bot pede uma categoria ativa. Cadastre-a no portal
  e responda com seu nome para completar a pendência.
* Forma de pagamento e estabelecimento não são presumidos.
* Valores em outras moedas, parcelas/total ambíguos e números pouco claros
  precisam de esclarecimento. Não há conversão automática de moeda.
* Envie um gasto por mensagem. Vários gastos ou serviços no mesmo áudio pedem
  uma descrição de um lançamento por vez.
* Uma pendência de outro serviço precisa ser concluída ou cancelada antes de
  começar um novo lançamento. Pendências expiram após duas horas.

Com confirmação desmarcada, dados completos e válidos são gravados e o bot
responde com descrição, valor, categoria, data e pagamento. A preferência
**Quero conferir o resumo antes de salvar** e a exigência global do administrador
também se aplicam aos gastos. O botão de confirmação é específico do serviço e
da pendência atual, associado à conta correta.

| Comando | Uso |
| --- | --- |
| `/gastos` | Ajuda e exemplo de gasto pessoal |
| `/categorias` | Lista as categorias ativas da conta |
| `/gasto descrição` | Endereça o texto explicitamente a Meus Gastos |
| `/combustivel` | Ajuda para abastecimentos |
| `/abastecer descrição` | Endereça o texto explicitamente a Meu Combustível |
| `/confirmar` ou `/salvar` | Confirma o lançamento completo pendente |
| `/cancelar` ou `/novo` | Descarta a pendência e permite começar outro lançamento |
| `/desvincular` | Remove o vínculo e as pendências do Telegram |

A cota diária e os limites de áudio são compartilhados pelos serviços. O Gemini
processa o áudio e a interpretação juntos; a alternativa OpenAI mantém as etapas
de transcrição e interpretação. Veja [TELEGRAM.md](TELEGRAM.md) para configuração,
gratuidade, limites e políticas dos provedores.

## Privacidade e alcance

Os gastos, categorias, consultas e pendências pertencem ao `wp_users.ID` da conta
autenticada. O compartilhamento de veículos não concede acesso aos gastos
pessoais. A API de gastos mantém esse isolamento inclusive para contas com
privilégios administrativos do WordPress. As ferramentas de privacidade do
WordPress exportam/apagam os dados da conta.

O responsável pelo portal pode desabilitar Meus Gastos globalmente ou bloquear
o serviço por conta, independentemente de combustível. Suspensão, revogação do
vínculo e mudanças de acesso são verificadas antes da gravação. Entregas
repetidas e reenvios da notificação não criam novos gastos. A fila guarda o
serviço de cada ID para evitar confundir um gasto com um abastecimento.

Este módulo registra gastos realizados. Não mantém saldo bancário, receitas,
faturas, parcelas futuras, orçamento ou conciliação. Compras no crédito são
registradas pelo valor informado e pela data do gasto, sem gerar parcelas
automaticamente.

## Extensão técnica

Tabelas: `pds_expenses`, `pds_expense_categories`, `pds_tg_expense_drafts`.
`pds_tg_updates.record_service` identifica o módulo do registro confirmado.
As atualizações do esquema preservam o identificador antigo de combustível.

Rotas autenticadas sob `/pds/v1/expenses`: `categories`, `categories/{id}`,
`entries`, `entries/{id}` e `report`. Os filtros são `from`, `to`, `category_id`,
`q`, `payment_method`; histórico aceita `page` e `per_page` (máximo 100).
Nonces WordPress e verificações de serviço/propriedade se aplicam a cada rota.

Eventos `pds_expense_created($entry,$user_id)`, `pds_expense_updated` e
`pds_expense_deleted` permitem novos módulos, sempre respeitando a propriedade.
O shortcode `[portal_gastos]` abre diretamente o serviço no portal.
