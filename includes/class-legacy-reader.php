<?php
defined( 'ABSPATH' ) || exit;

/** Reads legacy data as values. SQL statements are never executed. */
final class PDS_Legacy_Reader {
    const MAX_BYTES = 10485760;
    const MAX_ROWS = 50000;
    const COLUMNS = array(
        'usuarios' => array( 'id', 'nome', 'email', 'senha_hash', 'nivel_acesso', 'criado_em' ),
        'veiculos' => array( 'id', 'nome', 'id_proprietario', 'criado_em' ),
        'veiculos_compartilhados' => array( 'id', 'id_veiculo', 'id_usuario', 'status', 'convidado_em' ),
        'abastecimentos' => array( 'id', 'id_veiculo', 'id_usuario_que_registrou', 'data', 'km', 'tipo_combustivel', 'preco_litro', 'quantidade_litros', 'valor_pago', 'criado_em' ),
    );

    public static function prefix( $prefix ) {
        if ( ! is_string( $prefix ) || ! preg_match( '/^[A-Za-z0-9_]{0,40}$/D', $prefix ) ) { throw new InvalidArgumentException( 'Use apenas letras, números e sublinhado no prefixo das tabelas.' ); }
        return $prefix;
    }

    public static function database( $prefix = '', $options = array() ) {
        global $wpdb;
        self::prefix( $prefix ); $tables = array();
        foreach ( self::COLUMNS as $name => $columns ) {
            $table = $prefix . $name;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
                if ( 'veiculos_compartilhados' === $name ) { $tables[ $name ] = array(); continue; }
                throw new InvalidArgumentException( 'Tabela antiga não encontrada: ' . $table . '. Use o prefixo correto ou envie um backup SQL/JSON.' );
            }
            $tables[ $name ] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d', $table, self::MAX_ROWS + 1 ), ARRAY_A );
            if ( $wpdb->last_error ) { throw new RuntimeException( 'Não foi possível ler as tabelas antigas. Nenhum comando de alteração foi executado na origem.' ); }
        }
        return self::normalize( $tables, $options );
    }

    public static function file( $path, $extension, $prefix = '', $options = array() ) {
        self::prefix( $prefix );
        if ( ! is_readable( $path ) || filesize( $path ) > self::MAX_BYTES ) { throw new InvalidArgumentException( 'Envie um backup de até 10 MB.' ); }
        $contents = file_get_contents( $path );
        if ( false === $contents ) { throw new RuntimeException( 'Não foi possível ler o arquivo enviado.' ); }
        if ( 'json' === $extension ) {
            $tables = json_decode( $contents, true, 64 );
            if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $tables ) ) { throw new InvalidArgumentException( 'O arquivo não contém um JSON válido.' ); }
            $tables = $tables['tables'] ?? $tables;
            if ( ! is_array( $tables ) ) { throw new InvalidArgumentException( 'O JSON deve conter as tabelas do aplicativo antigo.' ); }
            $data = array();
            foreach ( self::COLUMNS as $name => $columns ) { $data[ $name ] = $tables[ $prefix . $name ] ?? array(); }
        } elseif ( 'sql' === $extension ) { $data = self::sql( $contents, $prefix ); }
        else { throw new InvalidArgumentException( 'Envie um arquivo .sql ou .json do banco antigo.' ); }
        return self::normalize( $data, $options );
    }

    public static function sql( $contents, $prefix = '' ) {
        self::prefix( $prefix );
        $tables = array_fill_keys( array_keys( self::COLUMNS ), array() );
        foreach ( self::statements( $contents ) as $statement ) {
            if ( preg_match( '/^REPLACE\s+/i', $statement ) ) { throw new InvalidArgumentException( 'Exporte o backup com comandos INSERT, sem REPLACE.' ); }
            if ( ! preg_match( '/^INSERT\s/i', $statement ) ) { continue; }
            if ( ! preg_match( '/^INSERT\s+(?:IGNORE\s+)?INTO\s+(?:(?:`[^`]+`|[A-Za-z0-9_]+)\s*\.\s*)?`?([A-Za-z0-9_]+)`?\s*(?:\(([^)]*)\))?\s+VALUES\s*(.*)$/is', $statement, $match ) ) {
                throw new InvalidArgumentException( 'Há um INSERT não reconhecido. Use a exportação SQL padrão, com valores literais.' );
            }
            $actual = $match[1]; $name = substr( $actual, strlen( $prefix ) );
            if ( substr( $actual, 0, strlen( $prefix ) ) !== $prefix || ! isset( self::COLUMNS[ $name ] ) ) { continue; }
            $columns = empty( trim( $match[2] ) ) ? null : array_map( static function ( $column ) {
                $column = trim( trim( $column ), '`' );
                if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $column ) ) { throw new InvalidArgumentException( 'Nome de coluna inválido no backup.' ); }
                return $column;
            }, explode( ',', $match[2] ) );
            foreach ( self::values( $match[3] ) as $values ) {
                $names = $columns ?? self::COLUMNS[ $name ];
                if ( null === $columns && 'abastecimentos' === $name && 9 === count( $values ) ) { $names = array_values( array_diff( $names, array( 'valor_pago' ) ) ); }
                if ( count( $values ) !== count( $names ) || count( array_unique( $names ) ) !== count( $names ) ) { throw new InvalidArgumentException( 'Quantidade de colunas incompatível com os valores na tabela ' . $name . '.' ); }
                $tables[ $name ][] = array_combine( $names, $values );
                if ( count( $tables[ $name ] ) > self::MAX_ROWS ) { throw new InvalidArgumentException( 'O backup ultrapassa o limite de 50 mil registros. Divida a exportação.' ); }
            }
        }
        return $tables;
    }

    private static function statements( $sql ) {
        $length = strlen( $sql ); $statement = ''; $quote = null;
        for ( $i = 0; $i < $length; $i++ ) {
            $c = $sql[ $i ]; $next = $sql[ $i + 1 ] ?? '';
            if ( null !== $quote ) {
                $statement .= $c;
                if ( '\\' === $c && $i + 1 < $length ) { $statement .= $sql[ ++$i ]; continue; }
                if ( $c === $quote ) {
                    if ( $next === $quote ) { $statement .= $sql[ ++$i ]; }
                    else { $quote = null; }
                }
                continue;
            }
            if ( '#' === $c || ( '-' === $c && '-' === $next && ( ! isset( $sql[ $i + 2 ] ) || ctype_space( $sql[ $i + 2 ] ) ) ) ) {
                while ( $i < $length && "\n" !== $sql[ $i ] ) { $i++; } $statement .= ' '; continue;
            }
            if ( '/' === $c && '*' === $next ) {
                $end = strpos( $sql, '*/', $i + 2 );
                if ( false === $end ) { throw new InvalidArgumentException( 'Comentário SQL incompleto.' ); }
                $i = $end + 1; $statement .= ' '; continue;
            }
            if ( in_array( $c, array( "'", '"', '`' ), true ) ) { $quote = $c; $statement .= $c; continue; }
            if ( ';' === $c ) { if ( trim( $statement ) ) { yield trim( $statement ); } $statement = ''; }
            else { $statement .= $c; }
        }
        if ( null !== $quote ) { throw new InvalidArgumentException( 'Texto SQL com aspas incompletas.' ); }
        if ( trim( $statement ) ) { yield trim( $statement ); }
    }

    private static function values( $sql ) {
        $length = strlen( $sql ); $i = 0;
        $space = static function () use ( &$i, $length, $sql ) { while ( $i < $length && ctype_space( $sql[ $i ] ) ) { $i++; } };
        while ( true ) {
            $space(); if ( $i >= $length ) { break; }
            if ( '(' !== $sql[ $i++ ] ) { throw new InvalidArgumentException( 'Use valores literais entre parênteses no backup SQL.' ); }
            $row = array();
            while ( true ) {
                $space(); $c = $sql[ $i ] ?? '';
                if ( "'" === $c || '"' === $c ) {
                    $quote = $c; $i++; $value = ''; $closed = false;
                    while ( $i < $length ) {
                        $c = $sql[ $i++ ];
                        if ( '\\' === $c ) {
                            if ( $i >= $length ) { break; }
                            $escape = $sql[ $i++ ];
                            $value .= array( '0' => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'Z' => "\x1a" )[ $escape ] ?? $escape;
                        } elseif ( $c === $quote ) {
                            if ( ( $sql[ $i ] ?? '' ) === $quote ) { $value .= $quote; $i++; }
                            else { $closed = true; break; }
                        } else { $value .= $c; }
                    }
                    if ( ! $closed ) { throw new InvalidArgumentException( 'Valor SQL com aspas incompletas.' ); }
                } else {
                    $start = $i; while ( $i < $length && ',' !== $sql[ $i ] && ')' !== $sql[ $i ] ) { $i++; }
                    $value = trim( substr( $sql, $start, $i - $start ) );
                    if ( 'NULL' === strtoupper( $value ) ) { $value = null; }
                    elseif ( ! preg_match( '/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/D', $value ) ) { throw new InvalidArgumentException( 'O backup contém uma expressão SQL. Exporte com valores literais.' ); }
                }
                $row[] = $value; $space(); $separator = $sql[ $i++ ] ?? '';
                if ( ')' === $separator ) { break; }
                if ( ',' !== $separator ) { throw new InvalidArgumentException( 'Separador SQL inválido.' ); }
            }
            yield $row; $space(); if ( $i >= $length ) { break; }
            if ( ',' !== $sql[ $i++ ] ) { throw new InvalidArgumentException( 'Não são aceitas cláusulas extras nos comandos INSERT.' ); }
        }
    }

    public static function compatible_hash( $hash ) {
        if ( ! is_string( $hash ) || strlen( $hash ) > 255 ) { return false; }
        if ( preg_match( '/^\$2[aby]\$(\d{2})\$[.\/A-Za-z0-9]{53}$/D', $hash, $match ) ) { return (int) $match[1] >= 4 && (int) $match[1] <= 16; }
        $info = password_get_info( $hash );
        return in_array( $info['algoName'] ?? '', array( 'argon2i', 'argon2id' ), true ) && in_array( $info['algo'] ?? null, password_algos(), true );
    }

    private static function id( $value, $context ) {
        if ( ! is_scalar( $value ) || ! preg_match( '/^[1-9][0-9]{0,17}$/D', (string) $value ) || (float) $value > PHP_INT_MAX ) { throw new InvalidArgumentException( 'ID inválido em ' . $context . '.' ); }
        return (int) $value;
    }

    private static function number( $value, $min, $max, $decimals, $context ) {
        if ( ! is_scalar( $value ) || is_bool( $value ) || ! is_numeric( $value ) || ! is_finite( (float) $value ) || (float) $value < $min || (float) $value > $max ) { throw new InvalidArgumentException( 'Valor inválido em ' . $context . '.' ); }
        return round( (float) $value, $decimals );
    }

    private static function date( $value, $context ) {
        if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m ) || (int) $m[1] < 1900 || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) { throw new InvalidArgumentException( 'Data inválida em ' . $context . '.' ); }
        return $value;
    }

    private static function timestamp( $value ) {
        if ( is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) && false !== strtotime( $value ) ) { return get_gmt_from_date( $value ); }
        return current_time( 'mysql', true );
    }

    public static function normalize( $tables, $options = array() ) {
        if ( ! is_array( $tables ) ) { throw new InvalidArgumentException( 'Estrutura de dados inválida.' ); }
        $out = array( 'users' => array(), 'vehicles' => array(), 'shares' => array(), 'records' => array() );
        $warnings = array(); $total = 0; $sets = array(); $emails = array();
        foreach ( self::COLUMNS as $table => $columns ) {
            if ( ! isset( $tables[ $table ] ) ) { $tables[ $table ] = array(); }
            if ( ! is_array( $tables[ $table ] ) ) { throw new InvalidArgumentException( 'A tabela ' . $table . ' deve ser uma lista.' ); }
            $total += count( $tables[ $table ] );
            foreach ( $tables[ $table ] as $row ) {
                if ( ! is_array( $row ) ) { throw new InvalidArgumentException( 'Registro inválido na tabela ' . $table . '.' ); }
                $id = self::id( $row['id'] ?? null, $table );
                if ( isset( $sets[ $table ][ $id ] ) ) { throw new InvalidArgumentException( 'ID repetido na tabela ' . $table . ': ' . $id . '.' ); }
                $sets[ $table ][ $id ] = true;
            }
        }
        if ( ! $total ) { throw new InvalidArgumentException( 'Não há dados para importar. O arquivo pode conter apenas a estrutura das tabelas, sem os comandos INSERT.' ); }
        if ( $total > self::MAX_ROWS ) { throw new InvalidArgumentException( 'O limite é de 50 mil registros por importação.' ); }
        $warning_counts = array( 'password' => 0, 'pending' => 0, 'missing_paid' => 0, 'zero_paid' => 0, 'future' => 0, 'duplicate_km' => 0 );
        foreach ( $tables['usuarios'] as $row ) {
            $id = (int) $row['id']; $name = sanitize_text_field( (string) ( $row['nome'] ?? '' ) );
            $raw_email = trim( (string) ( $row['email'] ?? '' ) ); $email = sanitize_email( $raw_email );
            if ( ! $name || mb_strlen( $name ) > 150 || ! is_email( $email ) || strtolower( $email ) !== strtolower( $raw_email ) || strlen( $email ) > 100 ) { throw new InvalidArgumentException( 'Confira nome e e-mail do usuário antigo ID ' . $id . '.' ); }
            if ( isset( $emails[ strtolower( $email ) ] ) ) { throw new InvalidArgumentException( 'Há e-mails duplicados entre os usuários antigos. Corrija o backup antes de importar.' ); }
            $emails[ strtolower( $email ) ] = true;
            $hash = is_string( $row['senha_hash'] ?? null ) && self::compatible_hash( $row['senha_hash'] ) ? $row['senha_hash'] : '';
            if ( ! $hash ) { $warning_counts['password']++; }
            $out['users'][] = array( 'id' => $id, 'name' => $name, 'email' => $email, 'hash' => $hash, 'created_at' => self::timestamp( $row['criado_em'] ?? '' ) );
        }
        $owners = array();
        foreach ( $tables['veiculos'] as $row ) {
            $id = (int) $row['id']; $owner = self::id( $row['id_proprietario'] ?? null, 'proprietário do veículo ' . $id );
            if ( ! isset( $sets['usuarios'][ $owner ] ) ) { throw new InvalidArgumentException( 'O proprietário do veículo antigo ' . $id . ' não está no backup de usuários.' ); }
            $name = sanitize_text_field( (string) ( $row['nome'] ?? '' ) );
            if ( ! $name || mb_strlen( $name ) > 150 ) { throw new InvalidArgumentException( 'Nome inválido no veículo antigo ' . $id . '.' ); }
            $owners[ $id ] = $owner;
            $out['vehicles'][] = array( 'id' => $id, 'owner' => $owner, 'name' => $name, 'created_at' => self::timestamp( $row['criado_em'] ?? '' ) );
        }
        foreach ( $tables['veiculos_compartilhados'] as $row ) {
            $id = (int) $row['id']; $vehicle = self::id( $row['id_veiculo'] ?? null, 'compartilhamento ' . $id ); $user = self::id( $row['id_usuario'] ?? null, 'compartilhamento ' . $id );
            if ( ! isset( $owners[ $vehicle ], $sets['usuarios'][ $user ] ) ) { throw new InvalidArgumentException( 'Referência ausente no compartilhamento antigo ' . $id . '.' ); }
            $status = $row['status'] ?? 'aceito';
            if ( ! in_array( $status, array( 'aceito', 'pendente' ), true ) ) { throw new InvalidArgumentException( 'Status inválido no compartilhamento antigo ' . $id . '.' ); }
            if ( 'pendente' === $status ) { $warning_counts['pending']++; }
            $out['shares'][] = array( 'id' => $id, 'vehicle' => $vehicle, 'user' => $user, 'status' => $status, 'created_at' => self::timestamp( $row['convidado_em'] ?? '' ) );
        }
        $odometers = array();
        foreach ( $tables['abastecimentos'] as $row ) {
            $id = (int) $row['id']; $vehicle = self::id( $row['id_veiculo'] ?? null, 'abastecimento ' . $id ); $author = self::id( $row['id_usuario_que_registrou'] ?? null, 'autor do abastecimento ' . $id );
            if ( ! isset( $owners[ $vehicle ], $sets['usuarios'][ $author ] ) ) { throw new InvalidArgumentException( 'Veículo ou autor ausente no abastecimento antigo ' . $id . '.' ); }
            $data = array( 'id' => $id, 'vehicle' => $vehicle, 'author' => $author, 'filled_on' => self::date( $row['data'] ?? null, 'abastecimento ' . $id ) );
            foreach ( array( 'km' => array( 'odometer', 0, 9999999999.99, 2 ), 'preco_litro' => array( 'price', 0.001, 9999999.999, 3 ), 'quantidade_litros' => array( 'liters', 0.001, 9999999.999, 3 ) ) as $key => $bounds ) { $data[ $bounds[0] ] = self::number( $row[ $key ] ?? null, $bounds[1], $bounds[2], $bounds[3], $key . ' do abastecimento ' . $id ); }
            $paid = $row['valor_pago'] ?? null;
            if ( null === $paid || '' === $paid ) {
                if ( empty( $options['missing_paid'] ) ) { throw new InvalidArgumentException( 'Valor pago ausente no abastecimento ' . $id . '. Se o app antigo calculava esse valor por preço × litros, marque essa opção na preparação.' ); }
                $paid = round( $data['price'] * $data['liters'], 2 ); $warning_counts['missing_paid']++;
            }
            $data['paid'] = self::number( $paid, 0, 9999999999.99, 2, 'valor pago do abastecimento ' . $id );
            if ( 0.0 === $data['paid'] ) { $warning_counts['zero_paid']++; }
            if ( ! in_array( $row['tipo_combustivel'] ?? null, array( 'G', 'A', 'D' ), true ) ) { throw new InvalidArgumentException( 'Combustível inválido no abastecimento antigo ' . $id . '.' ); }
            $data['fuel_type'] = $row['tipo_combustivel']; $data['full_tank'] = ! empty( $options['full_tank'] ) ? 1 : 0;
            $data['notes'] = ''; $data['created_at'] = self::timestamp( $row['criado_em'] ?? '' ); $data['updated_at'] = $data['created_at'];
            if ( $data['filled_on'] > current_time( 'Y-m-d' ) ) { $warning_counts['future']++; }
            $position = $vehicle . ':' . $data['odometer'];
            if ( isset( $odometers[ $position ] ) ) { $warning_counts['duplicate_km']++; } $odometers[ $position ] = true;
            $out['records'][] = $data;
        }
        $messages = array(
            'password' => 'usuário(s) sem hash de senha compatível. Contas novas precisarão recuperar a senha; contas existentes mantêm a senha do WordPress.',
            'pending' => 'compartilhamento(s) pendente(s) serão preservados sem liberar acesso ao veículo.',
            'missing_paid' => 'valor(es) pago(s) ausente(s) serão calculados por preço × litros, conforme a opção marcada.',
            'zero_paid' => 'abastecimento(s) com valor pago igual a zero serão preservados com esse valor.',
            'future' => 'registro(s) com data futura serão preservados como estão na origem.',
            'duplicate_km' => 'registro(s) repetem a quilometragem de outro abastecimento. Todos serão preservados.',
        );
        foreach ( $warning_counts as $key => $count ) { if ( $count ) { $warnings[] = $count . ' ' . $messages[ $key ]; } }
        $out['warnings'] = $warnings; $out['total'] = $total;
        return $out;
    }
}
