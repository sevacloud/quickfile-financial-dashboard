<?php
defined( 'ABSPATH' ) || exit;

/**
 * Read-only QuickFile REST API (v2) service.
 *
 * Endpoints used by the dashboard:
 *   GET /bank_accounts, /bank_accounts/{id}/balance, /bank_accounts/{id}/transactions,
 *   GET /reports/profit-and-loss, /reports/balance-sheet,
 *   GET /projects, /invoices, /purchases.
 *
 * Responses are mapped into the shapes the React app already consumes, so the
 * frontend is insulated from QuickFile's field naming.
 *
 * Every response is cached with WordPress transients (default 15 min).
 * No data is ever written back to QuickFile.
 * The API token lives only in PHP memory — it is never returned to the client.
 */
class QFD_API {

    // QuickFile REST API base URL — configurable via Settings → QuickFile Dashboard.
    public const DEFAULT_ENDPOINT = 'https://api-beta.quickfile.co.uk/';

    // Transient key prefix — used by the cache-flush routine.
    public const CACHE_PREFIX = 'qfd_cache_';

    // wp_remote_get timeout in seconds.
    private const HTTP_TIMEOUT = 30;

    // How many times to retry a failed HTTP request before giving up.
    private const MAX_RETRIES = 1;

    // Records requested per page on paginated list endpoints (QuickFile v2 max is 100).
    private const PAGE_SIZE = 100;

    // Safety cap on pages fetched per list call (PAGE_SIZE × MAX_PAGES records).
    private const MAX_PAGES = 50;

    private QFD_Auth $auth;
    private int $cache_ttl;

    public function __construct() {
        $this->auth      = new QFD_Auth();
        $this->cache_ttl = max( 60, (int) get_option( 'qfd_cache_duration', 900 ) );
    }

    // =========================================================================
    // Bank accounts
    // =========================================================================

    /**
     * GET /bank_accounts
     *
     * Returns all bank accounts visible to the token.
     * Used to populate the account selector in admin settings.
     *
     * @return array|WP_Error  List of { BankId, NominalCode, Name, BankType }.
     */
    public function get_bank_accounts(): array|WP_Error {
        return $this->cached( 'bank_accounts', function () {
            // 'types' omitted: it is a repeated query param and absent means all types.
            $raw = $this->get( 'bank_accounts', [ 'include_hidden' => 'false' ] );
            if ( is_wp_error( $raw ) ) {
                return $raw;
            }

            return array_map( fn( $a ) => [
                'BankId'      => (int) ( $a['id'] ?? 0 ),
                'NominalCode' => (int) ( $a['nominal_code'] ?? 0 ),
                'Name'        => (string) ( $a['name'] ?? '' ),
                'BankType'    => (string) ( $a['bank_type'] ?? '' ),
            ], (array) ( $raw['data'] ?? [] ) );
        } );
    }

    /**
     * GET /bank_accounts/{id}/balance for every selected account.
     *
     * @return array  Keyed by bankId string: { data: { CurrentBalance, BankName }, cached: bool, error: string|null }
     */
    public function get_account_balances(): array|WP_Error {
        $guard = $this->credentials_guard();
        if ( is_wp_error( $guard ) ) {
            return $guard;
        }

        $results = [];
        foreach ( $this->selected_accounts() as $key => $acc ) {
            $balance = $this->get_account_balance( $acc['bankId'] );
            if ( is_wp_error( $balance ) ) {
                $results[ $key ] = $this->err( $balance->get_error_message() );
            } else {
                $results[ $key ] = $this->ok( [ 'CurrentBalance' => $balance, 'BankName' => $acc['name'] ] );
            }
        }

        return $results;
    }

    /**
     * GET /bank_accounts/{id}/balance
     */
    public function get_account_balance( int $bank_id ): float|WP_Error {
        return $this->cached( "bal_{$bank_id}", function () use ( $bank_id ) {
            $raw = $this->get( "bank_accounts/{$bank_id}/balance" );
            if ( is_wp_error( $raw ) ) {
                return $raw;
            }
            return round( (float) ( $raw['balance'] ?? 0 ), 2 );
        } );
    }

    /**
     * GET /bank_accounts/{id}/transactions
     *
     * Returns every transaction for one bank account within a date range,
     * newest first, following pagination until the range is exhausted.
     *
     * @param  int    $bank_id  QuickFile bank account ID.
     * @param  string $from     Start date (YYYY-MM-DD).
     * @param  string $to       End date (YYYY-MM-DD).
     * @return array|WP_Error   List of { TransactionId, TransactionDate, Reference, Notes, Amount, Balance, TransactionType }.
     */
    public function search_transactions( int $bank_id, string $from, string $to ): array|WP_Error {
        return $this->cached( 'txn3_' . md5( "{$bank_id}|{$from}|{$to}" ), function () use ( $bank_id, $from, $to ) {
            $rows = $this->get_all( "bank_accounts/{$bank_id}/transactions", [
                'date_from'       => $from,
                'date_to'         => $to,
                'order_column'    => 'date',
                'order_direction' => 'desc',
            ] );
            if ( is_wp_error( $rows ) ) {
                return $rows;
            }

            // v2 amounts are signed: credits positive, debits negative.
            return array_map( fn( $t ) => [
                'TransactionId'   => (int) ( $t['id'] ?? 0 ),
                'TransactionDate' => substr( (string) ( $t['date'] ?? '' ), 0, 10 ),
                'Reference'       => (string) ( $t['reference'] ?? '' ),
                'Notes'           => (string) ( $t['notes'] ?? '' ),
                'Amount'          => (float) ( $t['amount'] ?? 0 ),
                'Balance'         => (float) ( $t['balance'] ?? 0 ),
                'TransactionType' => (float) ( $t['amount'] ?? 0 ) >= 0 ? 'CREDIT' : 'DEBIT',
            ], $rows );
        } );
    }

    // =========================================================================
    // Reports
    // =========================================================================

    /**
     * GET /reports/profit-and-loss
     *
     * Returns income and expenditure by nominal code for a date range, in the
     * NominalAccount shape the Annual Statement and Year Comparison views use.
     * Turnover maps to Income; cost of sales and expenses map to Expenditure.
     *
     * @return array|WP_Error  { NominalAccount: [ { NominalCode, NominalName, Balance, CategoryType } ] }
     */
    public function get_profit_and_loss( string $from, string $to ): array|WP_Error {
        return $this->cached( 'pnl_' . md5( "{$from}|{$to}" ), function () use ( $from, $to ) {
            $raw = $this->get( 'reports/profit-and-loss', [ 'date_from' => $from, 'date_to' => $to ] );
            if ( is_wp_error( $raw ) ) {
                return $raw;
            }

            $sections = [
                'turnovers'          => 'Income',
                'less_cost_of_sales' => 'Expenditure',
                'less_expenses'      => 'Expenditure',
            ];

            $nominals = [];
            foreach ( $sections as $section => $category ) {
                foreach ( (array) ( $raw[ $section ] ?? [] ) as $entry ) {
                    $nominals[] = [
                        'NominalCode'  => (int) ( $entry['nominal_code'] ?? 0 ),
                        'NominalName'  => (string) ( $entry['nominal_code_name'] ?? '' ),
                        'Balance'      => round( (float) ( $entry['amount'] ?? 0 ), 2 ),
                        'CategoryType' => $category,
                    ];
                }
            }

            return [ 'NominalAccount' => $nominals, 'Totals' => $raw['totals'] ?? [] ];
        } );
    }

    /**
     * GET /reports/balance-sheet
     *
     * @return array|WP_Error  Map of nominal code (string) → amount.
     */
    public function get_balance_sheet( string $to_date ): array|WP_Error {
        return $this->cached( 'bs2_' . md5( $to_date ), function () use ( $to_date ) {
            $raw = $this->get( 'reports/balance-sheet', [ 'date_to' => $to_date ] );
            if ( is_wp_error( $raw ) ) {
                return $raw;
            }

            $balances = [];
            foreach ( [ 'fixed_assets', 'current_assets', 'current_liabilities', 'long_term_liabilities', 'capital_and_reserves' ] as $section ) {
                foreach ( (array) ( $raw[ $section ] ?? [] ) as $item ) {
                    $code = (string) ( $item['nominal_code'] ?? '' );
                    if ( $code !== '' ) {
                        $balances[ $code ] = round( (float) ( $item['amount'] ?? 0 ), 2 );
                    }
                }
            }
            return $balances;
        } );
    }

    // =========================================================================
    // Projects
    // =========================================================================

    /**
     * GET /projects
     *
     * @return array|WP_Error  List of project tag names.
     */
    public function get_project_tags(): array|WP_Error {
        return $this->cached( 'project_tags2', function () {
            // limit max for /projects is 200 (QuickFile API limit).
            $raw = $this->get( 'projects', [ 'limit' => 200 ] );
            if ( is_wp_error( $raw ) ) {
                return $raw;
            }
            $tags = array_filter( array_map( 'strval', (array) $raw ), fn( $t ) => $t !== '' );
            sort( $tags, SORT_NATURAL | SORT_FLAG_CASE );
            return array_values( $tags );
        } );
    }

    /**
     * GET /invoices?tag_name=
     *
     * Sales invoices (income) tagged with a project within a date range.
     *
     * @return array|WP_Error  List of { InvoiceID, InvoiceNumber, IssueDate, ClientName, Description, Status, NetAmount, GrossAmount }.
     */
    public function get_invoices_by_tag( string $tag_name, string $from, string $to ): array|WP_Error {
        return $this->cached( 'inv2_' . md5( "{$tag_name}|{$from}|{$to}" ), function () use ( $tag_name, $from, $to ) {
            $rows = $this->get_all( 'invoices', [
                'tag_name'        => $tag_name,
                'type'            => 'invoice',
                'date_from'       => $from,
                'date_to'         => $to,
                'order_column'    => 'issue_date',
                'order_direction' => 'desc',
            ] );
            if ( is_wp_error( $rows ) ) {
                return $rows;
            }

            return array_map( fn( $i ) => [
                'InvoiceID'     => (int) ( $i['id'] ?? 0 ),
                'InvoiceNumber' => (string) ( $i['invoice_number'] ?? '' ),
                'IssueDate'     => substr( (string) ( $i['issue_date'] ?? '' ), 0, 10 ),
                'ClientName'    => (string) ( $i['client_company_name'] ?? $i['client_contact_name'] ?? '' ),
                'Description'   => (string) ( $i['description'] ?? '' ),
                'Status'        => (string) ( $i['status'] ?? '' ),
                'NetAmount'     => (float) ( $i['net_total'] ?? 0 ),
                'GrossAmount'   => (float) ( $i['gross_total'] ?? 0 ),
            ], $rows );
        } );
    }

    /**
     * GET /purchases?tag_name=
     *
     * Purchase invoices (spend) tagged with a project within a date range.
     *
     * @return array|WP_Error  List of { PurchaseID, ReceiptNumber, ReceiptDate, SupplierName, Description, Status, NetAmount, GrossAmount }.
     */
    public function get_purchases_by_tag( string $tag_name, string $from, string $to ): array|WP_Error {
        return $this->cached( 'pur_' . md5( "{$tag_name}|{$from}|{$to}" ), function () use ( $tag_name, $from, $to ) {
            $rows = $this->get_all( 'purchases', [
                'tag_name'        => $tag_name,
                'date_from'       => $from,
                'date_to'         => $to,
                'order_column'    => 'receipt_date',
                'order_direction' => 'desc',
            ] );
            if ( is_wp_error( $rows ) ) {
                return $rows;
            }

            return array_map( fn( $p ) => [
                'PurchaseID'    => (int) ( $p['id'] ?? 0 ),
                'ReceiptNumber' => (string) ( $p['receipt_number'] ?? '' ),
                'ReceiptDate'   => substr( (string) ( $p['receipt_date'] ?? '' ), 0, 10 ),
                'SupplierName'  => (string) ( $p['supplier_name'] ?? '' ),
                'Description'   => (string) ( $p['description'] ?? '' ),
                'Status'        => (string) ( $p['status'] ?? '' ),
                'NetAmount'     => (float) ( $p['net_total'] ?? 0 ),
                'GrossAmount'   => (float) ( $p['gross_total'] ?? 0 ),
            ], $rows );
        } );
    }

    // =========================================================================
    // HTTP layer
    // =========================================================================

    /**
     * Run $fetch on a cache miss and store its result; errors are never cached.
     */
    private function cached( string $key, callable $fetch ): mixed {
        $guard = $this->credentials_guard();
        if ( is_wp_error( $guard ) ) {
            return $guard;
        }

        $cache_key = self::CACHE_PREFIX . $key;
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        $result = $fetch();
        if ( ! is_wp_error( $result ) ) {
            set_transient( $cache_key, $result, $this->cache_ttl );
        }
        return $result;
    }

    /**
     * Fetch every page of a paginated list endpoint and return the merged data rows.
     */
    private function get_all( string $path, array $query ): array|WP_Error {
        $all    = [];
        $offset = 0;

        for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
            $raw = $this->get( $path, $query + [ 'limit' => self::PAGE_SIZE, 'offset' => $offset ] );
            if ( is_wp_error( $raw ) ) {
                return $raw;
            }

            $rows   = (array) ( $raw['data'] ?? [] );
            $all    = array_merge( $all, $rows );
            $offset += count( $rows );
            $total  = (int) ( $raw['paging']['total'] ?? 0 );

            // Stop on a short page, or once the reported total has been reached.
            if ( count( $rows ) < self::PAGE_SIZE || ( $total > 0 && $offset >= $total ) ) {
                break;
            }
        }

        return $all;
    }

    /**
     * GET a QuickFile REST path with automatic retry on transient network
     * failures. Validates the HTTP status and QuickFile's error envelope
     * before returning the decoded body.
     *
     * @param  string $path   Path relative to the base URL, e.g. 'bank_accounts'.
     * @param  array  $query  Query-string parameters.
     * @return array|WP_Error Decoded response body, or a descriptive WP_Error.
     */
    private function get( string $path, array $query = [] ): array|WP_Error {
        $url  = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $this->base_url() . ltrim( $path, '/' ) );
        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->auth->get_token(),
                'Accept'        => 'application/json',
            ],
            'timeout' => self::HTTP_TIMEOUT,
        ];

        $attempts = 0;
        do {
            $response = wp_remote_get( $url, $args );
            $attempts++;
            $retry = is_wp_error( $response ) && $attempts <= self::MAX_RETRIES;
        } while ( $retry );

        $http_code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
        $raw_body  = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );

        $this->append_log( 'GET /' . ltrim( $path, '/' ), $url, $http_code, $raw_body );

        if ( is_wp_error( $response ) ) {
            $error = new WP_Error( 'quickfile_network_error', sprintf( 'Network error (%s): %s', $path, $response->get_error_message() ) );
            $this->log_error( $path, $error );
            return $error;
        }

        $decoded = json_decode( $raw_body, true );

        if ( $http_code < 200 || $http_code >= 300 ) {
            // v2 error envelope: { "error": { "type", "code", "message" } }
            $message = is_array( $decoded ) ? (string) ( $decoded['error']['message'] ?? '' ) : '';
            $error   = new WP_Error(
                'quickfile_http_error',
                sprintf( 'QuickFile HTTP %d (%s): %s', $http_code, $path, $message !== '' ? $message : substr( $raw_body, 0, 300 ) )
            );
            $this->log_error( $path, $error );
            return $error;
        }

        if ( ! is_array( $decoded ) ) {
            $error = new WP_Error( 'quickfile_parse_error', "Unparseable response from QuickFile ({$path}): " . substr( $raw_body, 0, 300 ) );
            $this->log_error( $path, $error );
            return $error;
        }

        return $decoded;
    }

    private function base_url(): string {
        return rtrim( (string) get_option( 'qfd_endpoint', self::DEFAULT_ENDPOINT ) ?: self::DEFAULT_ENDPOINT, '/' ) . '/';
    }

    // =========================================================================
    // Diagnostics
    // =========================================================================

    /**
     * Call GET /bank_accounts uncached and return the raw exchange for the
     * settings page "Test Connection" button.
     */
    public function diagnostic_request(): array {
        $url      = $this->base_url() . 'bank_accounts';
        $response = wp_remote_get( $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->auth->get_token(),
                'Accept'        => 'application/json',
            ],
            'timeout' => self::HTTP_TIMEOUT,
        ] );
        $http_code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
        $raw_body  = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );

        $this->append_log( 'GET /bank_accounts', $url, $http_code, $raw_body );

        $decoded = json_decode( $raw_body, true );

        return [
            'url'             => $url,
            'http_code'       => $http_code,
            'rate_limit_left' => is_wp_error( $response ) ? null : wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ),
            'response_body'   => $decoded !== null ? $decoded : substr( $raw_body, 0, 1000 ),
        ];
    }

    public static function get_log(): array {
        return (array) json_decode( (string) get_option( 'qfd_api_log', '[]' ), true );
    }

    public static function clear_log(): void {
        delete_option( 'qfd_api_log' );
    }

    private function append_log( string $method, string $url, int $http_code, string $raw_body ): void {
        $log   = self::get_log();
        $log[] = [
            'time'      => current_time( 'Y-m-d H:i:s' ),
            'method'    => $method,
            'url'       => $url,
            'http_code' => $http_code,
            'response'  => substr( $raw_body, 0, 3000 ),
        ];
        // Keep last 30 entries.
        if ( count( $log ) > 30 ) {
            $log = array_slice( $log, -30 );
        }
        update_option( 'qfd_api_log', wp_json_encode( $log ), false );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Return a WP_Error immediately if no API token has been configured,
     * preventing pointless HTTP calls and confusing error messages.
     */
    private function credentials_guard(): true|WP_Error {
        if ( ! $this->auth->is_configured() ) {
            return new WP_Error(
                'quickfile_not_configured',
                'QuickFile API token is not configured. Visit Settings > QuickFile Dashboard.'
            );
        }
        return true;
    }

    /**
     * Build a successful per-account result envelope.
     */
    private function ok( array $data, bool $cached = false ): array {
        return [ 'data' => $data, 'cached' => $cached, 'error' => null ];
    }

    /**
     * Build a per-account error envelope (used when partial results are
     * acceptable, e.g. one of three bank accounts fails to respond).
     */
    private function err( string $message ): array {
        return [ 'data' => [], 'cached' => false, 'error' => $message ];
    }

    /**
     * Write a structured error to the PHP error log (server-side only).
     * Nothing from this call reaches the client.
     */
    private function log_error( string $endpoint, WP_Error $error ): void {
        error_log( sprintf(
            '[QuickFile Dashboard] GET /%s failed: [%s] %s',
            ltrim( $endpoint, '/' ),
            $error->get_error_code(),
            $error->get_error_message()
        ) );
    }

    /**
     * Selected accounts from settings, keyed by bankId string.
     *
     * @return array<string, array{bankId: int, nominalCode: int, name: string}>
     */
    public static function selected_accounts(): array {
        $list     = json_decode( (string) get_option( 'qfd_selected_accounts', '[]' ), true );
        $accounts = [];
        foreach ( is_array( $list ) ? $list : [] as $acc ) {
            $bank_id = (int) ( $acc['bankId'] ?? 0 );
            if ( $bank_id > 0 ) {
                $accounts[ (string) $bank_id ] = [
                    'bankId'      => $bank_id,
                    'nominalCode' => (int) ( $acc['nominalCode'] ?? 0 ),
                    'name'        => (string) ( $acc['name'] ?? '' ),
                ];
            }
        }
        return $accounts;
    }
}
