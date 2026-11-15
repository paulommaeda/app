<?php
defined( 'ABSPATH' ) || exit;

/** Reserve accounts retain their ledger; only money crossing into/out of daily accounts enters cash flow. */
final class PDS_Finance_Reserves {
    /** $alias is an internal SQL alias, never request input. Account zero retains legacy cash semantics. */
    public static function cash_scope( $alias, $uid ) {
        global $wpdb;
        if ( ! in_array( $alias, array( 'r', 'e', 'b', 'p' ), true ) ) { throw new InvalidArgumentException( 'Invalid financial query alias.' ); }
        return $wpdb->prepare( "NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}pds_fin_accounts ra INNER JOIN {$wpdb->prefix}pds_fin_account_types rt ON rt.user_id=ra.user_id AND rt.code=ra.kind WHERE ra.id={$alias}.account_id AND ra.user_id=%d AND rt.is_reserve=1)", $uid );
    }

    public static function transfers( $uid, $from, $to ) {
        global $wpdb; $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(CASE WHEN COALESCE(ft.is_reserve,0)=1 AND COALESCE(dt.is_reserve,0)=0 THEN t.amount ELSE 0 END),0) AS reserve_withdrawals,COALESCE(SUM(CASE WHEN COALESCE(ft.is_reserve,0)=0 AND COALESCE(dt.is_reserve,0)=1 THEN t.amount ELSE 0 END),0) AS reserve_contributions FROM {$p}pds_fin_transfers t INNER JOIN {$p}pds_fin_accounts f ON f.id=t.from_account_id AND f.user_id=t.user_id INNER JOIN {$p}pds_fin_accounts d ON d.id=t.to_account_id AND d.user_id=t.user_id LEFT JOIN {$p}pds_fin_account_types ft ON ft.user_id=f.user_id AND ft.code=f.kind LEFT JOIN {$p}pds_fin_account_types dt ON dt.user_id=d.user_id AND dt.code=d.kind WHERE t.user_id=%d AND t.status='completed' AND t.transferred_on BETWEEN %s AND %s", $uid, $from, $to ), ARRAY_A );
        if ( $wpdb->last_error || ! $row ) { return PDS_Finance::error( 'Não foi possível consultar os resgates e aportes.', 500 ); }
        return array( 'reserve_withdrawals' => (float) $row['reserve_withdrawals'], 'reserve_contributions' => (float) $row['reserve_contributions'] );
    }

    public static function balances( $accounts ) {
        $available = 0; $reserved = 0;
        foreach ( $accounts as $a ) {
            if ( ! $a['active'] || null === $a['balance'] ) { continue; }
            $cents = (int) round( $a['balance'] * 100 );
            if ( $a['is_reserve'] ) { $reserved += $cents; } else { $available += $cents; }
        }
        return array( 'available_balance' => $available / 100, 'reserve_balance' => $reserved / 100 );
    }

    private static function rollback( $error ) { global $wpdb; $wpdb->query( 'ROLLBACK' ); return $error; }

    public static function overview( $from, $to, $planning_warnings ) {
        global $wpdb; $uid = get_current_user_id(); $p = $wpdb->prefix; PDS_Finance_Account_Types::seed();
        if ( false === $wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' ) ) { return PDS_Finance::error( 'Não foi possível consultar o fluxo de caixa.', 500 ); }
        $bills = PDS_Finance_Planning::pending_totals( $uid, $from, $to, true );
        if ( $wpdb->last_error ) { return self::rollback( PDS_Finance::error( 'Não foi possível totalizar as contas previstas.', 500 ) ); }
        $scope = self::cash_scope( 'r', $uid );
        $receipts = $wpdb->get_results( $wpdb->prepare( "SELECT r.status,SUM(r.amount) AS amount FROM {$p}pds_fin_receipts r WHERE r.user_id=%d AND r.received_on BETWEEN %s AND %s AND $scope GROUP BY r.status", $uid, $from, $to ), ARRAY_A );
        if ( $wpdb->last_error ) { return self::rollback( PDS_Finance::error( 'Não foi possível consultar as entradas do fluxo.', 500 ) ); }
        $income = array( 'received' => 0.0, 'pending' => 0.0 ); foreach ( $receipts as $row ) { $income[ $row['status'] ] = (float) $row['amount']; }
        $scope = self::cash_scope( 'e', $uid );
        $direct = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(e.amount),0) FROM {$p}pds_expenses e WHERE e.user_id=%d AND e.card_id=0 AND e.payment_method<>'credit' AND e.spent_on BETWEEN %s AND %s AND $scope", $uid, $from, $to ) );
        if ( $wpdb->last_error ) { return self::rollback( PDS_Finance::error( 'Não foi possível consultar as saídas do fluxo.', 500 ) ); }
        $scope = self::cash_scope( 'b', $uid );
        $payments = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(b.amount),0) FROM {$p}pds_fin_bill_payments b WHERE b.user_id=%d AND b.paid_on BETWEEN %s AND %s AND $scope", $uid, $from, $to ) );
        if ( $wpdb->last_error ) { return self::rollback( PDS_Finance::error( 'Não foi possível consultar os pagamentos do fluxo.', 500 ) ); }
        $transfers = self::transfers( $uid, $from, $to ); if ( is_wp_error( $transfers ) ) { return self::rollback( $transfers ); }
        $invoices = $wpdb->get_results( $wpdb->prepare( "SELECT i.*,c.name AS card_name FROM {$p}pds_fin_invoices i INNER JOIN {$p}pds_fin_cards c ON c.id=i.card_id AND c.user_id=i.user_id WHERE i.user_id=%d AND i.due_on BETWEEN %s AND %s ORDER BY i.due_on,c.name", $uid, $from, $to ), ARRAY_A );
        if ( $wpdb->last_error ) { return self::rollback( PDS_Finance::error( 'Não foi possível consultar as faturas do período.', 500 ) ); }
        $open = 0.0; foreach ( $invoices as &$invoice ) { $invoice = PDS_Finance::cast_invoice( $invoice ); $open += $invoice['outstanding']; } unset( $invoice );
        $accounts = PDS_Finance::accounts()['accounts']; $balances = self::balances( $accounts );
        $cash_result = round( $income['received'] + $transfers['reserve_withdrawals'] - $transfers['reserve_contributions'] - $direct - $payments, 2 );
        $result = array_merge( array( 'from' => $from, 'to' => $to, 'received' => $income['received'], 'pending_income' => $income['pending'], 'direct_expenses' => $direct, 'invoice_payments' => $payments, 'cash_result' => $cash_result, 'open_invoices' => round( $open, 2 ), 'pending_payables' => $bills['amount'], 'unknown_payables' => $bills['unknown_count'], 'planning_warnings' => $planning_warnings, 'projected_result' => round( $cash_result + $income['pending'] - $open - $bills['amount'], 2 ), 'accounts' => $accounts, 'invoices' => $invoices ), $transfers, $balances );
        if ( false === $wpdb->query( 'COMMIT' ) ) { return self::rollback( PDS_Finance::error( 'Não foi possível concluir a consulta do fluxo.', 500 ) ); }
        return $result;
    }
}
