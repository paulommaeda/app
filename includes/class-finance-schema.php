<?php
defined( 'ABSPATH' ) || exit;

final class PDS_Finance_Schema {
    public static function create( $collate ) {
        global $wpdb; $p = $wpdb->prefix;
        dbDelta( "CREATE TABLE {$p}pds_fin_people (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            name varchar(80) NOT NULL,
            slug varchar(170) NOT NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_slug (user_id,slug)
        ) ENGINE=InnoDB $collate;" );
        dbDelta( "CREATE TABLE {$p}pds_fin_account_types (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            code varchar(16) NOT NULL,
            name varchar(80) NOT NULL,
            slug varchar(170) NOT NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_code (user_id,code),
            UNIQUE KEY user_slug (user_id,slug)
        ) ENGINE=InnoDB $collate;" );
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
        dbDelta( "CREATE TABLE {$p}pds_fin_transfers (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            from_account_id bigint(20) unsigned NOT NULL,
            to_account_id bigint(20) unsigned NOT NULL,
            amount decimal(12,2) NOT NULL,
            transferred_on date NOT NULL,
            notes varchar(500) NOT NULL DEFAULT '',
            status varchar(12) NOT NULL DEFAULT 'completed',
            request_key varchar(64) NOT NULL,
            created_at datetime NOT NULL,
            reversed_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY request_key (request_key),
            KEY user_date (user_id,transferred_on),
            KEY from_account (user_id,from_account_id,status,transferred_on),
            KEY to_account (user_id,to_account_id,status,transferred_on)
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
            source varchar(16) NOT NULL DEFAULT 'manual',
            external_ref varchar(96) DEFAULT NULL,
            recurring_id bigint(20) unsigned DEFAULT NULL,
            occurrence_month varchar(7) DEFAULT NULL,
            person_id bigint(20) unsigned DEFAULT NULL,
            shared_expense_id bigint(20) unsigned DEFAULT NULL,
            shared_installment_no smallint unsigned DEFAULT NULL,
            shared_installment_total smallint unsigned DEFAULT NULL,
            shared_planned_amount decimal(12,2) DEFAULT NULL,
            shared_planned_on date DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_date (user_id,received_on),
            KEY account_id (account_id),
            UNIQUE KEY external_ref (external_ref),
            UNIQUE KEY recurring_month (recurring_id,occurrence_month),
            UNIQUE KEY shared_part (shared_expense_id,shared_installment_no),
            KEY user_person (user_id,person_id,received_on)
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
        dbDelta( "CREATE TABLE {$p}pds_fin_recurring (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            kind varchar(12) NOT NULL,
            description varchar(180) NOT NULL,
            amount decimal(12,2) DEFAULT NULL,
            first_due date NOT NULL,
            due_day tinyint unsigned NOT NULL,
            next_month varchar(7) NOT NULL,
            end_month varchar(7) DEFAULT NULL,
            category_id bigint(20) unsigned NOT NULL DEFAULT 0,
            account_id bigint(20) unsigned NOT NULL DEFAULT 0,
            notes varchar(500) NOT NULL DEFAULT '',
            active tinyint(1) NOT NULL DEFAULT 1,
            source varchar(16) NOT NULL DEFAULT 'manual',
            external_ref varchar(96) DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_active (user_id,active),
            KEY next_month (active,next_month),
            UNIQUE KEY external_ref (external_ref)
        ) ENGINE=InnoDB $collate;" );
        dbDelta( "CREATE TABLE {$p}pds_fin_payables (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            recurring_id bigint(20) unsigned DEFAULT NULL,
            occurrence_month varchar(7) DEFAULT NULL,
            description varchar(180) NOT NULL,
            amount decimal(12,2) DEFAULT NULL,
            due_on date NOT NULL,
            category_id bigint(20) unsigned NOT NULL,
            account_id bigint(20) unsigned NOT NULL DEFAULT 0,
            notes varchar(500) NOT NULL DEFAULT '',
            status varchar(12) NOT NULL DEFAULT 'pending',
            expense_id bigint(20) unsigned DEFAULT NULL,
            source varchar(16) NOT NULL DEFAULT 'manual',
            external_ref varchar(96) DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_due (user_id,due_on),
            UNIQUE KEY recurring_month (recurring_id,occurrence_month),
            UNIQUE KEY expense_id (expense_id),
            UNIQUE KEY external_ref (external_ref)
        ) ENGINE=InnoDB $collate;" );
    }
}
