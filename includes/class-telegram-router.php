<?php
defined( 'ABSPATH' ) || exit;

/** One recognition per message, followed by the selected action's own validations. */
final class PDS_Telegram_Router {
    public static function handle( $job, $payload, $link ) {
        global $wpdb;
        $uid = get_current_user_id(); $text = trim( $payload['text'] ?? '' );
        $drafts = array(
            'fuel' => PDS_Telegram_Fuel::draft( $uid, $job['telegram_id'] ),
            'expense' => PDS_Telegram_Expenses::draft( $uid, $job['telegram_id'] ),
            'receipt' => PDS_Telegram_Receipts::draft( $uid, $job['telegram_id'] ),
            'payable' => PDS_Telegram_Payables::draft( $uid, $job['telegram_id'] ),
            'settlement' => PDS_Telegram_Settlements::draft( $uid, $job['telegram_id'] ),
        );
        $pending = array_filter( $drafts );
        $handlers = array( 'fuel' => 'PDS_Telegram_Fuel', 'expense' => 'PDS_Telegram_Expenses', 'receipt' => 'PDS_Telegram_Receipts', 'payable' => 'PDS_Telegram_Payables', 'settlement' => 'PDS_Telegram_Settlements' );
        $contexts = array( 'fuel' => 'combustivel', 'expense' => 'gastos', 'receipt' => 'recebimentos', 'payable' => 'contas_pagar', 'settlement' => 'baixas' );
        $services = array( 'fuel' => 'combustivel', 'expense' => 'gastos', 'receipt' => 'gastos', 'payable' => 'gastos', 'settlement' => 'gastos' );
        if ( in_array( $text, array( '/cancelar', '/novo' ), true ) ) {
            foreach ( array( 'pds_tg_drafts', 'pds_tg_expense_drafts', 'pds_tg_receipt_drafts', 'pds_tg_payable_drafts', 'pds_tg_settlement_drafts' ) as $table ) { $wpdb->delete( $wpdb->prefix . $table, array( 'user_id' => $uid ) ); }
            return PDS_Telegram::reply( 'Pendência descartada. Pode enviar um novo abastecimento, gasto ou recebimento.' );
        }
        if ( isset( $payload['callback'] ) ) {
            $kind = strpos( $payload['callback'], 'pds_set_' ) === 0 ? 'settlement' : ( strpos( $payload['callback'], 'pds_pay_' ) === 0 ? 'payable' : ( strpos( $payload['callback'], 'pds_rec_' ) === 0 ? 'receipt' : ( strpos( $payload['callback'], 'pds_exp_' ) === 0 ? 'expense' : 'fuel' ) ) );
            return $handlers[ $kind ]::handle( $job, $payload, $link );
        }
        if ( in_array( $text, array( '/confirmar', '/salvar' ), true ) ) {
            if ( count( $pending ) > 1 ) { return PDS_Telegram::reply( 'Há mais de uma pendência. Use o botão do resumo desejado ou /cancelar antes de começar outro lançamento.' ); }
            if ( ! $pending ) { return PDS_Telegram::reply( 'Não há um lançamento pendente. Pode enviar um gasto, recebimento ou abastecimento.' ); }
            $kind = array_key_first( $pending );
            return $handlers[ $kind ]::handle( $job, $payload, $link );
        }
        if ( '/pendentes' === $text ) {
            if ( array_diff( array_keys( $pending ), array( 'settlement' ) ) ) { return PDS_Telegram::reply( 'Há outro lançamento pendente. Termine-o ou use /cancelar antes de escolher uma previsão para baixa.' ); }
            return PDS_Telegram_Settlements::handle( $job, $payload, $link );
        }
        if ( in_array( $text, array( '/baixar', '/baixa', '/quitar' ), true ) ) { return PDS_Telegram::reply( "Baixe uma previsão existente por texto ou áudio.\nExemplos:\n• Dê baixa na luz prevista de novembro: paguei 195 reais ontem no Pix pela conta C6 PJ.\n• Recebi o salário previsto de novembro, 2 mil reais hoje na C6 PJ.\n• Paguei 300 reais da fatura C6 de novembro pela conta C6 PJ.\n/pendentes — escolher uma previsão\n/confirmar — confirmar baixa pendente\n/cancelar — preservar a previsão e descartar esta baixa" ); }
        if ( '/categorias' === $text ) {
            if ( ! pds_user_can_access_service( 'gastos' ) ) { return PDS_Telegram::reply( 'Meu Financeiro não está liberado para sua conta.' ); }
            $names = array_column( PDS_Expenses::category_list(), 'name' );
            return PDS_Telegram::reply( "Suas categorias ativas:\n• " . implode( "\n• ", array_slice( $names, 0, 40 ) ) . "\nVocê pode falar a categoria na mensagem ou personalizá-la no portal." );
        }
        if ( '/contas' === $text ) {
            if ( ! pds_user_can_access_service( 'gastos' ) ) { return PDS_Telegram::reply( 'Meu Financeiro não está liberado para sua conta.' ); }
            $names = array_column( array_filter( PDS_Finance::accounts()['accounts'], static fn( $a ) => $a['active'] ), 'name' );
            if ( ! $names ) { return PDS_Telegram::reply( 'Cadastre sua conta em Meu Financeiro → Contas no portal. Depois diga: recebi 100 reais na conta C6 pessoa jurídica.' ); }
            return PDS_Telegram::reply( "Suas contas ativas:\n• " . implode( "\n• ", array_slice( $names, 0, 20 ) ) . ( count( $names ) > 20 ? "\nConsulte as demais contas no portal." : '' ) . "\nInforme o nome da conta que recebeu o dinheiro. Não é necessário enviar número da conta ou senha." );
        }
        if ( in_array( $text, array( '/recebimentos', '/recebimento', '/receber', '/recebi' ), true ) ) {
            if ( ! pds_user_can_access_service( 'gastos' ) ) { return PDS_Telegram::reply( 'Meu Financeiro não está liberado para sua conta.' ); }
            return PDS_Telegram::reply( "Exemplo: recebi 100 reais na conta C6 pessoa jurídica.\nO recebimento entra na conta já cadastrada e aparece em Meu Financeiro → Recebimentos.\n/contas — ver contas ativas\n/confirmar — salvar resumo pendente\n/cancelar — descartar pendência\nEnvie um recebimento realizado por mensagem, por texto ou áudio." );
        }
        if ( in_array( $text, array( '/contasapagar', '/contapagar', '/pagar', '/boleto' ), true ) ) { return PDS_Telegram::reply( "Exemplo: cadastre conta de luz de 200 reais vencendo dia 20, todo mês.\nTambém pode dizer: vou pagar telefone de 80 reais amanhã, uma vez.\nA previsão fica pendente. A previsão não dá baixa automática. Quando pagar, use /baixar ou o portal.\n/confirmar — confirmar cadastro pendente\n/cancelar — descartar cadastro" ); }
        if ( '/gastos' === $text || '/gasto' === $text ) {
            return PDS_Telegram::reply( "Exemplo: gastei 35 reais no almoço hoje, no Pix.\nO bot registra descrição, valor, data e categoria em Meu Financeiro.\n/categorias — ver categorias\n/confirmar — salvar resumo pendente\n/cancelar — descartar pendência\nEnvie um gasto por mensagem, por texto ou áudio." );
        }
        if ( '/combustivel' === $text ) { return PDS_Telegram::reply( 'Para abastecimentos, fale veículo, combustível, litros, preço, valor pago, odômetro e se completou o tanque. Use /veiculos para escolher o padrão.' ); }
        if ( preg_match( '#^/veiculos?$|^/veiculo\s#', $text ) ) {
            return $drafts['expense'] || $drafts['receipt'] || $drafts['payable'] || $drafts['settlement'] ? PDS_Telegram::reply( 'Você tem um lançamento financeiro pendente. Termine esse lançamento ou use /cancelar antes de mudar para combustível.' ) : PDS_Telegram_Fuel::handle( $job, $payload, $link );
        }
        $forced = '';
        if ( preg_match( '#^/(?:baixar|baixa|quitar)\s+(.+)$#us', $text, $m ) ) { $text = $m[1]; $forced = 'settlement'; }
        elseif ( preg_match( '#^/(?:gasto|gastos|despesa)\s+(.+)$#us', $text, $m ) ) { $text = $m[1]; $forced = 'expense'; }
        elseif ( preg_match( '#^/(?:recebimento|recebimentos|receber|recebi|receita)\s+(.+)$#us', $text, $m ) ) { $text = $m[1]; $forced = 'receipt'; }
        elseif ( preg_match( '#^/(?:contapagar|contasapagar|pagar|boleto)\s+(.+)$#us', $text, $m ) ) { $text = $m[1]; $forced = 'payable'; }
        elseif ( preg_match( '#^/(?:abastecer|abastecimento)\s+(.+)$#us', $text, $m ) ) { $text = $m[1]; $forced = 'fuel'; }
        elseif ( strpos( $text, '/' ) === 0 ) { return PDS_Telegram::reply( 'Comando não reconhecido. Use /ajuda, /gastos, /recebimentos ou /combustivel.' ); }
        $fuel_access = pds_user_can_access_service( 'combustivel' ); $finance_access = pds_user_can_access_service( 'gastos' );
        $income_hint = (bool) preg_match( '/\b(?:recebi|receber|recebimento|entrou|depositaram)\b|me pagaram/iu', $text );
        $fuel_hint = ! $income_hint && (bool) preg_match( '/abastec|od[oô]metro|(?:completei|encheu|encher).*tanque|litros?.*(?:gasolina|etanol|diesel)/iu', $text );
        if ( ( 'fuel' === $forced || ( ! $forced && $fuel_hint ) ) && ! $fuel_access ) { return PDS_Telegram::reply( 'O serviço Meu Combustível não está liberado para sua conta.' ); }
        if ( ( in_array( $forced, array( 'expense', 'receipt', 'payable', 'settlement' ), true ) || ( ! $forced && $income_hint ) ) && ! $finance_access ) { return PDS_Telegram::reply( 'O serviço Meu Financeiro não está liberado para sua conta.' ); }
        if ( ! $fuel_access && ! $finance_access ) { return PDS_Telegram::reply( 'Não há um serviço liberado para registrar essa mensagem. Consulte sua conta no portal.' ); }
        $config = PDS_Telegram_Client::settings();
        if ( ( $link['consent_provider'] ?? 'openai' ) !== $config['ai_provider'] ) { return PDS_Telegram::reply( 'O portal mudou o provedor de reconhecimento para ' . PDS_Telegram_Client::provider_name() . '. Abra Minha conta → Telegram e confirme o uso das mensagens.' ); }
        if ( isset( $payload['audio'] ) && ( $payload['audio']['duration'] <= 0 || $payload['audio']['duration'] > (int) $config['voice_seconds'] || $payload['audio']['size'] > PDS_Telegram_Client::MAX_AUDIO_BYTES ) ) { return PDS_Telegram::reply( 'Envie um áudio de até ' . (int) $config['voice_seconds'] . ' segundos e 5 MB, com um lançamento por mensagem.' ); }
        if ( ! $text && ! isset( $payload['audio'] ) ) { return PDS_Telegram::reply( 'Envie um áudio ou texto com o gasto, recebimento ou abastecimento.' ); }
        if ( ! PDS_Telegram::quota( 'tg-ai', (string) $uid, (int) $config['daily_limit'], DAY_IN_SECONDS ) ) { return PDS_Telegram::reply( 'Você atingiu o limite diário de ' . (int) $config['daily_limit'] . ' mensagens com reconhecimento. O cadastro pelo portal continua disponível.' ); }
        $active_kind = $forced ?: ( $pending ? array_key_first( $pending ) : '' );
        $previous = $active_kind && ! $forced && $drafts[ $active_kind ] ? ( 'fuel' === $active_kind ? $drafts['fuel']['data'] : array( $active_kind => 'settlement' === $active_kind ? PDS_Telegram_Settlements::context( $drafts[ $active_kind ] ) : $drafts[ $active_kind ]['data'] ) ) : array();
        if ( $forced && $drafts[ $forced ] ) { $previous = 'fuel' === $forced ? $drafts['fuel']['data'] : array( $forced => 'settlement' === $forced ? PDS_Telegram_Settlements::context( $drafts[ $forced ] ) : $drafts[ $forced ]['data'] ); }
        $extra = array(
            'active_service' => $active_kind ? $contexts[ $active_kind ] : '',
            'expense_categories' => $finance_access ? array_column( PDS_Expenses::category_list(), 'name' ) : array(),
            'available_services' => array_values( array_filter( array( $fuel_access ? 'combustivel' : '', $finance_access ? 'gastos' : '', $finance_access ? 'recebimentos' : '', $finance_access ? 'contas_pagar' : '', $finance_access ? 'baixas' : '' ) ) ),
        );
        if ( $finance_access ) {
            $extra['financial_choices'] = array(
                'cards' => array_values( array_map( static fn( $c ) => $c['name'] . ( $c['last_four'] ? ' final ' . $c['last_four'] : '' ), array_filter( PDS_Finance::cards()['cards'], static fn( $c ) => $c['active'] ) ) ),
                'accounts' => array_values( array_column( array_filter( PDS_Finance::accounts()['accounts'], static fn( $a ) => $a['active'] ), 'name' ) ),
            );
        }
        $result = PDS_Telegram_Client::recognize( $text, $payload['audio']['file_id'] ?? null, wp_date( 'Y-m-d', (int) $payload['date'], wp_timezone() ), $previous, $extra );
        if ( is_wp_error( $result ) ) { return PDS_Telegram::reply( $result->get_error_message() ); }
        wp_cache_delete( $uid, 'user_meta' ); wp_cache_delete( 'pds_telegram_settings', 'options' );
        $fresh = PDS_Telegram::link( $uid ); $latest_config = PDS_Telegram_Client::settings();
        if ( ! $fresh || $fresh['telegram_id'] !== $job['telegram_id'] || ! $latest_config['enabled'] || $fresh['consent_provider'] !== $latest_config['ai_provider'] ) { return PDS_Telegram::reply( 'O vínculo ou a configuração mudou durante o reconhecimento. Confira sua conta no portal antes de reenviar.' ); }
        $kind = $result['intent'];
        if ( ! isset( $services[ $kind ] ) || ( $forced && $kind !== $forced ) ) { return PDS_Telegram::reply( 'Envie um gasto, recebimento ou abastecimento por mensagem. Exemplo de recebimento: recebi 100 reais na conta C6 pessoa jurídica. Exemplo de previsão: vou receber 500 reais dia 20 na conta C6 PJ. Cadastre uma conta de luz informando o vencimento. Para outro assunto, use o portal.' ); }
        if ( ! pds_user_can_access_service( $services[ $kind ] ) ) { return PDS_Telegram::reply( 'O acesso a esse serviço mudou. Confira sua conta no portal.' ); }
        foreach ( $pending as $pending_kind => $draft ) {
            if ( $pending_kind !== $kind ) { return PDS_Telegram::reply( 'Há um lançamento de outro serviço pendente. Complete-o ou use /cancelar antes de começar este novo lançamento.' ); }
        }
        if ( ! $forced && ! $pending && in_array( $kind, array( 'expense', 'receipt' ), true ) ) { $intercepted = PDS_Telegram_Settlements::intercept( $job, $payload, $link, $result ); if ( null !== $intercepted ) { return $intercepted; } }
        return $handlers[ $kind ]::handle( $job, $payload, $link, $result );
    }
}
