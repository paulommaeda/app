<?php
defined( 'ABSPATH' ) || exit;

/** A Telegram link identifies a person. Its selected financial space never impersonates the owner. */
final class PDS_Telegram_Spaces {
    public static function selected( $link ) { return (int) ( $link['finance_space_id'] ?? 0 ) ?: PDS_Finance_Spaces::personal( $link['user_id'] ); }
    private static function pending( $uid ) {
        global $wpdb;
        foreach ( array( 'pds_tg_drafts', 'pds_tg_expense_drafts', 'pds_tg_receipt_drafts', 'pds_tg_payable_drafts', 'pds_tg_settlement_drafts' ) as $table ) { if ( $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}{$table} WHERE user_id=%d AND expires_at>%d", $uid, time() ) ) ) { return true; } }
        return false;
    }
    private static function bind_job( &$job, $space ) {
        global $wpdb; $job['finance_space_id'] = (int) $space;
        if ( ! empty( $job['id'] ) ) { $wpdb->update( $wpdb->prefix . 'pds_tg_updates', array( 'finance_space_id' => (int) $space ), array( 'id' => $job['id'], 'user_id' => get_current_user_id() ) ); }
    }
    public static function prepare( &$job, $payload, $link ) {
        global $wpdb; PDS_Finance_Spaces::reset(); $text = trim( $payload['text'] ?? '' ); $uid = get_current_user_id();
        if ( '/financeiros' === $text || '/financeiro' === $text || preg_match( '/^\/financeiro\s+([1-9][0-9]{0,17})$/D', $text, $m ) ) {
            self::bind_job( $job, PDS_Finance_Spaces::personal( $uid ) );
            $spaces = PDS_Finance_Spaces::accessible(); $selected = self::selected( $link );
            if ( isset( $m[1] ) ) {
                $id = (int) $m[1]; $found = array_values( array_filter( $spaces, static fn( $s ) => $s['id'] === $id ) );
                if ( ! $found ) { return PDS_Telegram::reply( 'Você não tem acesso a esse financeiro. Use /financeiros para ver os seus.' ); }
                if ( self::pending( $uid ) ) { return PDS_Telegram::reply( 'Há um lançamento pendente. Confirme-o no financeiro original ou use /cancelar antes de trocar de financeiro.' ); }
                if ( false === $wpdb->update( $wpdb->prefix . 'pds_tg_links', array( 'finance_space_id' => $id ), array( 'user_id' => $uid, 'telegram_id' => $job['telegram_id'] ) ) ) { return PDS_Telegram::reply( 'Não consegui alterar o financeiro. Tente novamente.' ); }
                self::bind_job( $job, $id );
                return PDS_Telegram::reply( 'Financeiro selecionado: ' . $found[0]['name'] . ' (' . $found[0]['role_label'] . ").\nAs próximas mensagens serão registradas aqui. A escolha no portal é independente." );
            }
            if ( ! $spaces ) { return PDS_Telegram::reply( 'Meu Financeiro não está liberado para sua conta.' ); }
            $lines = array(); foreach ( $spaces as $s ) { $lines[] = ( $s['id'] === $selected ? '✓ ' : '• ' ) . $s['name'] . ' — ' . $s['role_label'] . "\n/financeiro " . $s['id']; }
            return PDS_Telegram::reply( "Seus financeiros:\n\n" . implode( "\n\n", $lines ) . "\n\nEscolha pelo comando acima e aguarde a confirmação antes de enviar novos lançamentos. Cada participante vincula o próprio Telegram em Minha conta." );
        }
        if ( in_array( $text, array( '/cancelar', '/novo' ), true ) ) { self::bind_job( $job, PDS_Finance_Spaces::personal( $uid ) ); return null; }
        // A queued message keeps its original destination, even if the selection changes later.
        $space = array_key_exists( 'finance_space_id', $job ) ? ( (int) $job['finance_space_id'] ?: PDS_Finance_Spaces::personal( $uid ) ) : self::selected( $link );
        $job['finance_space_id'] = $space;
        if ( ! empty( $job['id'] ) ) { $wpdb->update( $wpdb->prefix . 'pds_tg_updates', array( 'finance_space_id' => $space ), array( 'id' => $job['id'], 'user_id' => $uid ) ); }
        $ok = PDS_Finance_Spaces::select( $space );
        if ( is_wp_error( $ok ) ) {
            // Fuel-only users can still use their personal fuel service, with no financial choices exposed.
            if ( $space === PDS_Finance_Spaces::personal( $uid ) && ! pds_user_can_access_service( 'gastos' ) ) { return null; }
            return PDS_Telegram::reply( $ok->get_error_message() );
        }
        foreach ( array( 'pds_tg_drafts', 'pds_tg_expense_drafts', 'pds_tg_receipt_drafts', 'pds_tg_payable_drafts', 'pds_tg_settlement_drafts' ) as $table ) {
            $other = $wpdb->get_var( $wpdb->prepare( "SELECT finance_space_id FROM {$wpdb->prefix}{$table} WHERE user_id=%d AND expires_at>%d", $uid, time() ) );
            if ( null !== $other && ( (int) $other ?: PDS_Finance_Spaces::personal( $uid ) ) !== $space ) { return PDS_Telegram::reply( 'Existe uma pendência em outro financeiro. Use /cancelar ou volte ao financeiro original para confirmá-la.' ); }
        }
        return null;
    }
    public static function live( $job, $write = true ) {
        $owner = PDS_Finance_Spaces::owner( PDS_Finance_Spaces::id() ); if ( $owner ) { wp_cache_delete( $owner, 'user_meta' ); }
        return (int) ( $job['finance_space_id'] ?? PDS_Finance_Spaces::id() ) === PDS_Finance_Spaces::id() && PDS_Finance_Spaces::can( $write ? 'write' : 'read' );
    }
    public static function caption( $reply ) {
        $s = PDS_Finance_Spaces::row( PDS_Finance_Spaces::id() );
        if ( $s && PDS_Finance_Spaces::can() && isset( $reply['text'] ) ) { $reply['text'] = 'Financeiro: ' . $s['name'] . "\n\n" . $reply['text']; }
        return $reply;
    }
}
