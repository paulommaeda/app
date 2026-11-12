<?php
defined( 'ABSPATH' ) || exit;

/** One recognition per message, followed by the selected service's own validations. */
final class PDS_Telegram_Router {
    public static function handle( $job, $payload, $link ) {
        global $wpdb; $uid = get_current_user_id(); $text = trim( $payload['text'] ?? '' );
        $fuel = PDS_Telegram_Fuel::draft( $uid, $job['telegram_id'] ); $expense = PDS_Telegram_Expenses::draft( $uid, $job['telegram_id'] );
        if ( in_array( $text, array( '/cancelar', '/novo' ), true ) ) { foreach ( array( 'pds_tg_drafts', 'pds_tg_expense_drafts' ) as $table ) { $wpdb->delete( $wpdb->prefix . $table, array( 'user_id' => $uid ) ); } return PDS_Telegram::reply( 'Pendência descartada. Pode enviar um novo abastecimento ou gasto.' ); }
        if ( isset( $payload['callback'] ) ) { return strpos( $payload['callback'], 'pds_exp_' ) === 0 ? PDS_Telegram_Expenses::handle( $job, $payload, $link ) : PDS_Telegram_Fuel::handle( $job, $payload, $link ); }
        if ( in_array( $text, array( '/confirmar', '/salvar' ), true ) ) {
            if ( $fuel && $expense ) { return PDS_Telegram::reply( 'Há duas pendências. Use o botão do resumo desejado ou /cancelar antes de começar outro lançamento.' ); }
            return $expense ? PDS_Telegram_Expenses::handle( $job, $payload, $link ) : PDS_Telegram_Fuel::handle( $job, $payload, $link );
        }
        if ( '/categorias' === $text ) {
            if ( ! pds_user_can_access_service( 'gastos' ) ) { return PDS_Telegram::reply( 'Meus Gastos não está liberado para sua conta.' ); }
            $names = array_column( PDS_Expenses::category_list(), 'name' ); return PDS_Telegram::reply( "Suas categorias ativas:\n• " . implode( "\n• ", array_slice( $names, 0, 40 ) ) . "\nVocê pode falar a categoria na mensagem ou personalizá-la no portal." );
        }
        if ( '/gastos' === $text || '/gasto' === $text ) { return PDS_Telegram::reply( "Exemplo: gastei 35 reais no almoço hoje, no Pix.\nO bot registra descrição, valor, data e categoria em Meus Gastos.\n/categorias — ver categorias\n/confirmar — salvar resumo pendente\n/cancelar — descartar pendência\nEnvie um gasto por mensagem, por texto ou áudio." ); }
        if ( '/combustivel' === $text ) { return PDS_Telegram::reply( 'Para abastecimentos, fale veículo, combustível, litros, preço, valor pago, odômetro e se completou o tanque. Use /veiculos para escolher o padrão.' ); }
        if ( preg_match( '#^/veiculos?$|^/veiculo\s#', $text ) ) { return $expense ? PDS_Telegram::reply( 'Você tem um gasto pendente. Termine esse lançamento ou use /cancelar antes de mudar para combustível.' ) : PDS_Telegram_Fuel::handle( $job, $payload, $link ); }
        $forced = '';
        if ( preg_match( '#^/(?:gasto|gastos|despesa)\s+(.+)$#us', $text, $m ) ) { $text = $m[1]; $forced = 'gastos'; }
        elseif ( preg_match( '#^/(?:abastecer|abastecimento)\s+(.+)$#us', $text, $m ) ) { $text = $m[1]; $forced = 'combustivel'; }
        elseif ( strpos( $text, '/' ) === 0 ) { return PDS_Telegram::reply( 'Comando não reconhecido. Use /ajuda, /gastos ou /combustivel.' ); }
        $fuel_access = pds_user_can_access_service( 'combustivel' ); $expense_access = pds_user_can_access_service( 'gastos' );
        $fuel_hint = (bool) preg_match( '/abastec|od[oô]metro|(?:completei|encheu|encher).*tanque|litros?.*(?:gasolina|etanol|diesel)/iu', $text );
        if ( ( 'combustivel' === $forced || $fuel_hint ) && ! $fuel_access ) { return PDS_Telegram::reply( 'O serviço Meu Combustível não está liberado para sua conta.' ); }
        if ( 'gastos' === $forced && ! $expense_access ) { return PDS_Telegram::reply( 'O serviço Meus Gastos não está liberado para sua conta.' ); }
        if ( ! $fuel_access && ! $expense_access ) { return PDS_Telegram::reply( 'Não há um serviço liberado para registrar essa mensagem. Consulte sua conta no portal.' ); }
        $config = PDS_Telegram_Client::settings();
        if ( ( $link['consent_provider'] ?? 'openai' ) !== $config['ai_provider'] ) { return PDS_Telegram::reply( 'O portal mudou o provedor de reconhecimento para ' . PDS_Telegram_Client::provider_name() . '. Abra Minha conta → Telegram e confirme o uso das mensagens.' ); }
        if ( isset( $payload['audio'] ) && ( $payload['audio']['duration'] <= 0 || $payload['audio']['duration'] > (int) $config['voice_seconds'] || $payload['audio']['size'] > PDS_Telegram_Client::MAX_AUDIO_BYTES ) ) { return PDS_Telegram::reply( 'Envie um áudio de até ' . (int) $config['voice_seconds'] . ' segundos e 5 MB, com um lançamento por mensagem.' ); }
        if ( ! $text && ! isset( $payload['audio'] ) ) { return PDS_Telegram::reply( 'Envie um áudio ou texto com o gasto ou abastecimento.' ); }
        if ( ! PDS_Telegram::quota( 'tg-ai', (string) $uid, (int) $config['daily_limit'], DAY_IN_SECONDS ) ) { return PDS_Telegram::reply( 'Você atingiu o limite diário de ' . (int) $config['daily_limit'] . ' mensagens com reconhecimento. O cadastro pelo portal continua disponível.' ); }
        $active = $forced ?: ( $expense ? 'gastos' : ( $fuel ? 'combustivel' : '' ) );
        $previous = $expense ? array( 'expense' => $expense['data'] ) : ( $fuel ? $fuel['data'] : array() );
        $extra = array( 'active_service' => $active, 'expense_categories' => $expense_access ? array_column( PDS_Expenses::category_list(), 'name' ) : array(), 'available_services' => array_values( array_filter( array( $fuel_access ? 'combustivel' : '', $expense_access ? 'gastos' : '' ) ) ) );
        if ( $expense_access ) { $extra['financial_choices'] = array( 'cards' => array_values( array_map( static fn( $c ) => $c['name'] . ( $c['last_four'] ? ' final ' . $c['last_four'] : '' ), array_filter( PDS_Finance::cards()['cards'], static fn( $c ) => $c['active'] ) ) ), 'accounts' => array_column( array_filter( PDS_Finance::accounts()['accounts'], static fn( $a ) => $a['active'] ), 'name' ) ); }
        $result = PDS_Telegram_Client::recognize( $text, $payload['audio']['file_id'] ?? null, wp_date( 'Y-m-d', (int) $payload['date'], wp_timezone() ), $previous, $extra );
        if ( is_wp_error( $result ) ) { return PDS_Telegram::reply( $result->get_error_message() ); }
        wp_cache_delete( $uid, 'user_meta' ); wp_cache_delete( 'pds_telegram_settings', 'options' );
        $fresh = PDS_Telegram::link( $uid ); $latest_config = PDS_Telegram_Client::settings();
        if ( ! $fresh || $fresh['telegram_id'] !== $job['telegram_id'] || ! $latest_config['enabled'] || $fresh['consent_provider'] !== $latest_config['ai_provider'] ) { return PDS_Telegram::reply( 'O vínculo ou a configuração mudou durante o reconhecimento. Confira sua conta no portal antes de reenviar.' ); }
        $target = 'expense' === $result['intent'] ? 'gastos' : ( 'fuel' === $result['intent'] ? 'combustivel' : '' );
        if ( ! $target || ( $forced && $target !== $forced ) ) { return PDS_Telegram::reply( 'Envie um gasto ou um abastecimento por mensagem. Exemplo de gasto: paguei 35 reais no almoço hoje. Para outro assunto, use o portal.' ); }
        if ( ! pds_user_can_access_service( $target ) ) { return PDS_Telegram::reply( 'O acesso a esse serviço mudou. Confira sua conta no portal.' ); }
        if ( ( 'gastos' === $target && $fuel ) || ( 'combustivel' === $target && $expense ) ) { return PDS_Telegram::reply( 'Há um lançamento de outro serviço pendente. Complete-o ou use /cancelar antes de começar este novo lançamento.' ); }
        return 'gastos' === $target ? PDS_Telegram_Expenses::handle( $job, $payload, $link, $result ) : PDS_Telegram_Fuel::handle( $job, $payload, $link, $result );
    }
}
