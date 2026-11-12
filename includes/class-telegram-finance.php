<?php
defined( 'ABSPATH' ) || exit;

/** Resolve only accounts and cards owned by the linked WordPress user. No model-generated IDs are accepted. */
final class PDS_Telegram_Finance {
    const FIELDS = array( 'payment_method', 'card_hint', 'account_hint', 'installment_mode', 'installments' );
    public static function merge( $previous, $entry ) {
        $previous = is_array( $previous ) ? $previous : array();
        if ( null !== ( $entry['payment_method'] ?? null ) && $entry['payment_method'] !== ( $previous['payment_method'] ?? '' ) ) { $previous['installments'] = null; $previous['installment_mode'] = null; }
        foreach ( self::FIELDS as $key ) { if ( null !== ( $entry[ $key ] ?? null ) ) { $previous[ $key ] = is_string( $entry[ $key ] ) ? sanitize_text_field( $entry[ $key ] ) : $entry[ $key ]; if ( 'card_hint' === $key ) { $previous['card_id'] = 0; } if ( 'account_hint' === $key ) { $previous['account_id'] = 0; } } }
        foreach ( $entry['uncertain_fields'] ?? array() as $key ) { if ( in_array( $key, self::FIELDS, true ) ) { $previous[ $key ] = null; if ( 'card_hint' === $key ) { $previous['card_id'] = 0; $previous['card_uncertain'] = true; } if ( 'account_hint' === $key ) { $previous['account_id'] = 0; } } }
        if ( null !== ( $entry['card_hint'] ?? null ) && ! in_array( 'card_hint', $entry['uncertain_fields'] ?? array(), true ) ) { $previous['card_uncertain'] = false; }
        return $previous;
    }
    private static function choose( $rows, $hint, $old = 0, $implicit = false ) {
        $rows = array_values( array_filter( $rows, static fn( $row ) => $row['active'] ) );
        if ( $hint ) { $key = PDS_Finance_Import::normalized( $hint ); $exact = array(); $partial = array(); foreach ( $rows as $row ) { $name = PDS_Finance_Import::normalized( $row['name'] ); $last = $row['last_four'] ?? ''; if ( $key === $name || ( $last && ( $key === $last || $key === $name . $last ) ) ) { $exact[] = $row['id']; } elseif ( strlen( $key ) >= 3 && strpos( $name, $key ) !== false ) { $partial[] = $row['id']; } } return count( $exact ) === 1 ? $exact[0] : ( ! $exact && count( $partial ) === 1 ? $partial[0] : 0 ); }
        foreach ( $rows as $row ) { if ( (int) $row['id'] === (int) $old ) { return (int) $old; } }
        return $implicit && ! $old && count( $rows ) === 1 ? $rows[0]['id'] : 0;
    }
    public static function resolve( $data ) {
        $missing = array(); $method = $data['payment_method'] ?? ''; $data['payment_method'] = $method ?: ''; $data['card_id'] = (int) ( $data['card_id'] ?? 0 ); $data['account_id'] = (int) ( $data['account_id'] ?? 0 );
        if ( ! pds_user_can_access_service( 'gastos' ) ) { return array( $data, $method || ! empty( $data['card_hint'] ) || ! empty( $data['account_hint'] ) ? array( 'acesso ao serviço Meu Financeiro para informar conta/cartão; consulte o portal' ) : array() ); }
        if ( 'credit' === $method ) {
            $cards = PDS_Finance::cards()['cards']; $data['card_id'] = ! empty( $data['card_uncertain'] ) ? 0 : self::choose( $cards, $data['card_hint'] ?? '', $data['card_id'], true ); $data['account_id'] = 0;
            if ( ! $data['card_id'] ) { $names = array_column( array_filter( $cards, static fn( $c ) => $c['active'] ), 'name' ); $missing[] = $names ? 'qual cartão de crédito: ' . implode( ', ', array_slice( $names, 0, 10 ) ) : 'cadastrar um cartão em Meu Financeiro → Cartões no portal'; }
            $n = $data['installments'] ?? null; $mode = $data['installment_mode'] ?? null;
            if ( 'single' === $mode ) { if ( null !== $n && $n !== 1 ) { $missing[] = 'confirmar o parcelamento: à vista corresponde a uma parcela'; $n = null; } else { $n = 1; } } elseif ( null === $mode && is_int( $n ) && $n >= 1 ) { $mode = $n === 1 ? 'single' : 'split'; }
            if ( ! is_int( $n ) || $n < 1 || $n > 120 || ( 'split' === $mode && $n < 2 ) ) { $missing[] = 'se foi à vista no crédito ou parcelado e o número de parcelas (até 120)'; } else { $data['installments'] = $n; $data['installment_mode'] = $mode; }
        } else {
            $data['card_id'] = 0; $data['installments'] = 1;
            if ( ! empty( $data['card_hint'] ) && ! $method ) { $missing[] = 'se o pagamento foi no crédito ou no débito'; }
            if ( ! empty( $data['account_hint'] ) ) { $data['account_id'] = self::choose( PDS_Finance::accounts()['accounts'], $data['account_hint'], $data['account_id'] ); if ( ! $data['account_id'] ) { $missing[] = 'o nome exato de uma conta ativa cadastrada no portal para a saída do dinheiro'; } }
        }
        return array( $data, $missing );
    }
    public static function fields( $data ) { return array_intersect_key( $data, array_fill_keys( array( 'payment_method', 'card_id', 'account_id', 'installments' ), true ) ); }
    public static function summary( $data ) {
        $out = ''; if ( ! empty( $data['card_id'] ) ) { $card = PDS_Finance::find( 'cards', $data['card_id'] ); if ( ! is_wp_error( $card ) ) { $out .= "\nCartão: " . $card['name'] . ' · ' . (int) ( $data['installments'] ?? 1 ) . 'x'; } }
        if ( ! empty( $data['account_id'] ) ) { $account = PDS_Finance::find( 'accounts', $data['account_id'] ); if ( ! is_wp_error( $account ) ) { $out .= "\nConta: " . $account['name']; } } return $out;
    }
}
