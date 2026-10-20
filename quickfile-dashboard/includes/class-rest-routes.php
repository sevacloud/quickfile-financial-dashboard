<?php
defined( 'ABSPATH' ) || exit;

class QFD_REST {

    private const NAMESPACE = 'quickfile/v1';

    public function init(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        $auth = [ $this, 'check_permission' ];

        register_rest_route( self::NAMESPACE, '/balances', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_balances' ],
            'permission_callback' => $auth,
            'args'                => $this->date_range_args_optional(),
        ] );

        register_rest_route( self::NAMESPACE, '/monthly-summary', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_monthly_summary' ],
            'permission_callback' => $auth,
            'args'                => $this->date_range_args(),
        ] );

        register_rest_route( self::NAMESPACE, '/annual-statement', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_annual_statement' ],
            'permission_callback' => $auth,
            'args'                => $this->date_range_args(),
        ] );

        register_rest_route( self::NAMESPACE, '/projects', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_projects' ],
            'permission_callback' => $auth,
            'args'                => $this->date_range_args(),
        ] );

        register_rest_route( self::NAMESPACE, '/utilities', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_utilities' ],
            'permission_callback' => $auth,
            'args'                => $this->date_range_args(),
        ] );

        register_rest_route( self::NAMESPACE, '/year-comparison', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_year_comparison' ],
            'permission_callback' => $auth,
            'args'                => [
                'years' => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => is_string( $v ) && preg_match( '/^\d{4}(,\d{4})*$/', $v ),
                ],
            ],
        ] );

        // Prior-year figures — per nominal code, admin-editable.
        register_rest_route( self::NAMESPACE, '/prior-year', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_prior_year' ],
                'permission_callback' => $auth,
                'args'                => [
                    'fy' => [
                        'required'          => true,
                        'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v >= 2000,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'save_prior_year_figure' ],
                'permission_callback' => fn() => current_user_can( 'manage_options' ),
                'args'                => [
                    'fy'     => [
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                    'code'   => [
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'amount' => [
                        'required'          => true,
                        'sanitize_callback' => fn( $v ) => round( (float) $v, 2 ),
                    ],
                ],
            ],
        ] );

        // Project budgets — stored in wp_options, editable by trustees.
        register_rest_route( self::NAMESPACE, '/project-budgets', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_project_budgets' ],
                'permission_callback' => $auth,
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'save_project_budget' ],
                'permission_callback' => $auth,
                'args'                => [
                    'tag' => [
                        'required'          => true,
                        'validate_callback' => fn( $v ) => is_string( $v ) && trim( $v ) !== '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'budget' => [
                        'required'          => true,
                        'validate_callback' => fn( $v ) => is_numeric( $v ) && (float) $v >= 0,
                        'sanitize_callback' => fn( $v ) => round( (float) $v, 2 ),
                    ],
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/transactions', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_transactions' ],
            'permission_callback' => $auth,
            'args'                => array_merge(
                $this->date_range_args(),
                [
                    'account' => [
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ]
            ),
        ] );

        // Cache-bust endpoint (admin only).
        register_rest_route( self::NAMESPACE, '/flush-cache', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'flush_cache' ],
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( self::NAMESPACE, '/closing-balances', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_closing_balances' ],
            'permission_callback' => $auth,
            'args'                => [
                'fy' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/balance-sheet', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_balance_sheet' ],
            'permission_callback' => $auth,
            'args'                => [
                'to' => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ),
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );
    }

    // -----------------------------------------------------------------
    // Callbacks
    // -----------------------------------------------------------------

    public function get_balances(): WP_REST_Response|WP_Error {
        $api     = new QFD_API();
        $results = $api->get_account_balances();

        if ( is_wp_error( $results ) ) {
            return $results;
        }

        $response = [];
        foreach ( $results as $key => $envelope ) {
            if ( $envelope['error'] !== null ) {
                $response[ $key ] = [ '_error' => $envelope['error'] ];
                continue;
            }
            $response[ $key ] = array_merge( (array) $envelope['data'], [ '_cached' => $envelope['cached'] ] );
        }

        return new WP_REST_Response( $response );
    }

    public function get_monthly_summary( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        [ $from, $to ] = $this->extract_dates( $req );
        $api            = new QFD_API();
        $result         = [];

        foreach ( QFD_API::selected_accounts() as $key => $acc ) {
            $transactions = $api->search_transactions( $acc['bankId'], $from, $to );
            if ( is_wp_error( $transactions ) ) {
                $result[ $key ] = [ '_error' => $transactions->get_error_message() ];
                continue;
            }
            $result[ $key ] = $this->aggregate_monthly( $transactions );
        }

        return new WP_REST_Response( $result );
    }

    public function get_annual_statement( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        [ $from, $to ] = $this->extract_dates( $req );
        $api            = new QFD_API();

        // The v2 reports have no bank-account filter, so only combined figures are available.
        $combined = $api->get_profit_and_loss( $from, $to );
        if ( is_wp_error( $combined ) ) {
            return $combined;
        }

        return new WP_REST_Response( [ 'combined' => $combined ] );
    }

    public function get_transactions( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        [ $from, $to ] = $this->extract_dates( $req );
        $account_key   = (string) $req->get_param( 'account' );

        $acc = QFD_API::selected_accounts()[ $account_key ] ?? null;
        if ( $acc === null ) {
            return new WP_Error( 'not_found', 'Account not configured.', [ 'status' => 404 ] );
        }

        $api          = new QFD_API();
        $transactions = $api->search_transactions( $acc['bankId'], $from, $to );
        if ( is_wp_error( $transactions ) ) {
            return $transactions;
        }

        $balance = $api->get_account_balance( $acc['bankId'] );

        return new WP_REST_Response( [
            'meta'         => [
                'BankName'       => $acc['name'],
                'CurrentBalance' => is_wp_error( $balance ) ? null : $balance,
            ],
            'transactions' => $transactions,
        ] );
    }

    /**
     * Income (tagged sales invoices) vs spend (tagged purchases) per project tag.
     */
    public function get_projects( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        [ $from, $to ] = $this->extract_dates( $req );
        $api            = new QFD_API();

        $tags = $api->get_project_tags();
        if ( is_wp_error( $tags ) ) {
            return $tags;
        }

        $result = [];
        foreach ( $tags as $tag ) {
            $invoices = $api->get_invoices_by_tag( $tag, $from, $to );
            if ( is_wp_error( $invoices ) ) {
                return $invoices;
            }
            $purchases = $api->get_purchases_by_tag( $tag, $from, $to );
            if ( is_wp_error( $purchases ) ) {
                return $purchases;
            }

            $income = round( array_sum( array_column( $invoices, 'GrossAmount' ) ), 2 );
            $spend  = round( array_sum( array_column( $purchases, 'GrossAmount' ) ), 2 );

            $result[] = [
                'tag'       => $tag,
                'invoices'  => $invoices,
                'purchases' => $purchases,
                'income'    => $income,
                'spend'     => $spend,
                'net'       => round( $income - $spend, 2 ),
            ];
        }

        return new WP_REST_Response( $result );
    }

    public function get_utilities( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        [ $from, $to ] = $this->extract_dates( $req );
        $api            = new QFD_API();

        // Project tags applied to utility purchase invoices in QuickFile.
        $utility_tags = [
            'Gas', 'Electricity', 'Water',
            'Broadband', 'Insurance', 'Alarm',
        ];

        $result = [];
        foreach ( $utility_tags as $tag ) {
            $purchases = $api->get_purchases_by_tag( $tag, $from, $to );
            if ( is_wp_error( $purchases ) ) {
                return $purchases;
            }

            $months = [];
            foreach ( $purchases as $purchase ) {
                $month = substr( $purchase['ReceiptDate'], 0, 7 ); // YYYY-MM
                if ( $month === '' ) {
                    continue;
                }
                $months[ $month ] ??= [ 'income' => 0.0, 'expenditure' => 0.0 ];
                $months[ $month ]['expenditure'] += $purchase['GrossAmount'];
            }
            ksort( $months );
            $result[ $tag ] = $months;
        }

        return new WP_REST_Response( $result );
    }

    public function get_year_comparison( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $years  = array_map( 'intval', explode( ',', $req->get_param( 'years' ) ) );
        $api    = new QFD_API();
        $fy_m   = max( 1, min( 12, (int) get_option( 'qfd_fy_start_month', 4 ) ) );
        $end_m  = $fy_m === 1 ? 12 : $fy_m - 1;
        $result = [];

        foreach ( $years as $year ) {
            $end_year = $fy_m === 1 ? $year : $year + 1;
            $end_day  = (int) date( 't', mktime( 0, 0, 0, $end_m, 1, $end_year ) );
            $from     = sprintf( '%04d-%02d-01', $year, $fy_m );
            $to       = sprintf( '%04d-%02d-%02d', $end_year, $end_m, $end_day );

            $pnl = $api->get_profit_and_loss( $from, $to );
            if ( is_wp_error( $pnl ) ) return $pnl;

            $bs               = $api->get_balance_sheet( $to );
            $account_balances = [];
            if ( ! is_wp_error( $bs ) ) {
                foreach ( QFD_API::selected_accounts() as $key => $acc ) {
                    $account_balances[ $key ] = $bs[ (string) $acc['nominalCode'] ] ?? null;
                }
            }

            $result[ $year ] = array_merge( $pnl, [ '_balances' => $account_balances, '_to' => $to ] );
        }

        return new WP_REST_Response( $result );
    }

    public function get_prior_year( WP_REST_Request $req ): WP_REST_Response {
        $fy  = $req->get_param( 'fy' );
        $raw = (string) get_option( "qfd_prior_year_{$fy}", '{}' );
        $data = json_decode( $raw, true );
        return new WP_REST_Response( is_array( $data ) ? $data : [] );
    }

    public function save_prior_year_figure( WP_REST_Request $req ): WP_REST_Response {
        $fy     = $req->get_param( 'fy' );
        $code   = $req->get_param( 'code' );
        $amount = $req->get_param( 'amount' );
        $key    = "qfd_prior_year_{$fy}";
        $stored = json_decode( (string) get_option( $key, '{}' ), true );
        if ( ! is_array( $stored ) ) {
            $stored = [];
        }
        $stored[ $code ] = $amount;
        update_option( $key, wp_json_encode( $stored ), false );
        return new WP_REST_Response( [ 'saved' => true, 'fy' => $fy, 'code' => $code, 'amount' => $amount ] );
    }

    /**
     * Project budgets, keyed by project tag name (QuickFile v2 tags have no ID).
     */
    public function get_project_budgets(): WP_REST_Response {
        return new WP_REST_Response( (object) $this->stored_budgets() );
    }

    public function save_project_budget( WP_REST_Request $req ): WP_REST_Response {
        $tag     = $req->get_param( 'tag' );
        $budget  = $req->get_param( 'budget' );
        $budgets = $this->stored_budgets();
        $budgets[ $tag ] = $budget;
        update_option( 'qfd_project_budgets', wp_json_encode( $budgets ), false );
        return new WP_REST_Response( [ 'saved' => true, 'tag' => $tag, 'budget' => $budget ] );
    }

    private function stored_budgets(): array {
        $budgets = json_decode( (string) get_option( 'qfd_project_budgets', '{}' ), true );
        return is_array( $budgets ) ? array_map( 'floatval', $budgets ) : [];
    }

    public function flush_cache(): WP_REST_Response {
        global $wpdb;
        $prefix  = $wpdb->esc_like( '_transient_' . QFD_API::CACHE_PREFIX ) . '%';
        $timeout = $wpdb->esc_like( '_transient_timeout_' . QFD_API::CACHE_PREFIX ) . '%';
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $prefix,
            $timeout
        ) );
        return new WP_REST_Response( [ 'flushed' => true ] );
    }

    public function get_closing_balances( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $fy_label = $req->get_param( 'fy' );
        $hist     = json_decode( (string) get_option( 'qfd_historical_years', '[]' ), true );
        $year     = null;
        foreach ( (array) $hist as $row ) {
            if ( ( $row['label'] ?? '' ) === $fy_label ) { $year = $row; break; }
        }
        if ( ! $year ) {
            return new WP_Error( 'not_found', 'Historical year not configured.', [ 'status' => 404 ] );
        }

        $api = new QFD_API();
        $bs  = $api->get_balance_sheet( $year['to'] );
        if ( is_wp_error( $bs ) ) return $bs;

        $result = [];
        foreach ( QFD_API::selected_accounts() as $key => $acc ) {
            $result[ $key ] = [
                'balance' => $bs[ (string) $acc['nominalCode'] ] ?? null,
                'as_of'   => $year['to'],
            ];
        }
        return new WP_REST_Response( $result );
    }

    public function get_balance_sheet( WP_REST_Request $req ): WP_REST_Response|WP_Error {
        $to  = $req->get_param( 'to' );
        $api = new QFD_API();
        $bs  = $api->get_balance_sheet( $to );
        if ( is_wp_error( $bs ) ) return $bs;

        $accounts = [];
        foreach ( QFD_API::selected_accounts() as $key => $acc ) {
            $accounts[ $key ] = [
                'balance' => $bs[ (string) $acc['nominalCode'] ] ?? null,
            ];
        }
        return new WP_REST_Response( [ 'accounts' => $accounts, 'raw' => $bs ] );
    }

    // -----------------------------------------------------------------
    // Permission
    // -----------------------------------------------------------------

    public function check_permission(): bool {
        return QFD_Roles::current_user_is_trustee();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function date_range_args_optional(): array {
        $date_validate = fn( $v ) => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v );
        return [
            'from' => [ 'required' => false, 'validate_callback' => $date_validate ],
            'to'   => [ 'required' => false, 'validate_callback' => $date_validate ],
        ];
    }

    private function date_range_args(): array {
        $date_validate = fn( $v ) => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v );
        return [
            'from' => [ 'required' => true, 'validate_callback' => $date_validate ],
            'to'   => [ 'required' => true, 'validate_callback' => $date_validate ],
        ];
    }

    private function extract_dates( WP_REST_Request $req ): array {
        return [
            sanitize_text_field( $req->get_param( 'from' ) ),
            sanitize_text_field( $req->get_param( 'to' ) ),
        ];
    }

    /**
     * Bucket bank transactions (signed amounts) into monthly income / expenditure.
     */
    private function aggregate_monthly( array $transactions ): array {
        $months = [];
        foreach ( $transactions as $txn ) {
            $date = $txn['TransactionDate'] ?? '';
            if ( $date === '' ) {
                continue;
            }
            $month  = substr( $date, 0, 7 ); // YYYY-MM
            $amount = (float) ( $txn['Amount'] ?? 0 );

            $months[ $month ] ??= [ 'income' => 0.0, 'expenditure' => 0.0 ];
            if ( $amount >= 0 ) {
                $months[ $month ]['income'] += abs( $amount );
            } else {
                $months[ $month ]['expenditure'] += abs( $amount );
            }
        }
        ksort( $months );
        return $months;
    }
}
