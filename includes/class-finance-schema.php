<?php
defined( 'ABSPATH' ) || exit;

final class PDS_Finance_Schema {
    public static function create( $collate ) {
        global $wpdb; $p = $wpdb->prefix;
        dbDelta( "CREATE TABLE {$p}pds_fin_accounts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            name varchar(80) NOT NULL,
            kind varchar(16) NOT NULL DEFAULT 'checking',
            opening_balance decimal(12,2) NOT NULL DEFAULT 0,
            opening_on date NOT NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id)
        ) ENGINE=InnoDB $collate;" );
        dbDelta( "CREATE TABLE {$p}pds_fin_receipts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            account_id bigint(20) unsigned NOT NULL,
            description varchar(180) NOT NULL,
            amount decimal(12,2) NOT NULL,
            received_on date NOT NULL,
            status varchar(12) NOT NULL DEFAULT 'received',
            notes varchar(500) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_date (user_id,received_on),
            KEY account_id (account_id)
        ) ENGINE=InnoDB $collate;" );
        dbDelta( "CREATE TABLE {$p}pds_fin_cards (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            name varchar(80) NOT NULL,
            last_four varchar(4) NOT NULL DEFAULT '',
            credit_limit decimal(12,2) NOT NULL DEFAULT 0,
            closing_day tinyint unsigned NOT NULL,
            due_day tinyint unsigned NOT NULL,
            on_close_next tinyint(1) NOT NULL DEFAULT 1,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id)
        ) ENGINE=InnoDB $collate;" );
        dbDelta( "CREATE TABLE {$p}pds_fin_invoices (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            card_id bigint(20) unsigned NOT NULL,
            invoice_month varchar(7) NOT NULL,
            closing_on date NOT NULL,
            due_on date NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY card_month (card_id,invoice_month),
            KEY user_due (user_id,due_on)
        ) ENGINE=InnoDB $collate;" );
        dbDelta( "CREATE TABLE {$p}pds_fin_installments (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            expense_id bigint(20) unsigned NOT NULL,
            card_id bigint(20) unsigned NOT NULL,
            invoice_id bigint(20) unsigned NOT NULL,
            installment_no smallint unsigned NOT NULL,
            installment_total smallint unsigned NOT NULL,
            amount decimal(12,2) NOT NULL,
            confirmed tinyint(1) NOT NULL DEFAULT 0,
            bank_ref varchar(64) DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY expense_part (expense_id,installment_no),
            UNIQUE KEY bank_ref (bank_ref),
            KEY invoice_id (invoice_id),
            KEY user_id (user_id)
        ) ENGINE=InnoDB $collate;" );
        dbDelta( "CREATE TABLE {$p}pds_fin_bill_payments (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            invoice_id bigint(20) unsigned NOT NULL,
            account_id bigint(20) unsigned NOT NULL,
            amount decimal(12,2) NOT NULL,
            paid_on date NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY invoice_id (invoice_id),
            KEY user_account (user_id,account_id,paid_on)
        ) ENGINE=InnoDB $collate;" );
        dbDelta( "CREATE TABLE {$p}pds_fin_adjustments (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            invoice_id bigint(20) unsigned NOT NULL,
            description varchar(180) NOT NULL,
            amount decimal(12,2) NOT NULL,
            external_ref varchar(64) DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY external_ref (external_ref),
            KEY invoice_id (invoice_id),
            KEY user_id (user_id)
        ) ENGINE=InnoDB $collate;" );
    }
}
