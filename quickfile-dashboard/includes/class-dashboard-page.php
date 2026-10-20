<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers the front-end dashboard page as a virtual WordPress page
 * using a rewrite rule — no page needs to be created in the admin backend.
 */
class QFD_Dashboard {

    private const SLUG = 'quickfile-dashboard';

    public function init(): void {
        add_action( 'init', [ $this, 'add_rewrite_rule' ] );
        add_filter( 'query_vars', [ $this, 'add_query_var' ] );
        add_action( 'template_redirect', [ $this, 'intercept_request' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function add_rewrite_rule(): void {
        add_rewrite_rule(
            '^' . self::SLUG . '(/.*)?$',
            'index.php?qfd_dashboard=1',
            'top'
        );
    }

    public function add_query_var( array $vars ): array {
        $vars[] = 'qfd_dashboard';
        return $vars;
    }

    public function intercept_request(): void {
        if ( ! get_query_var( 'qfd_dashboard' ) ) {
            return;
        }

        if ( ! QFD_Roles::current_user_is_trustee() ) {
            $login_url = home_url( '/' . QFD_Login::SLUG . '/' );
            // Return to the requested view (e.g. /quickfile-dashboard/projects) after login.
            $requested = home_url( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) );
            $login_url = add_query_arg( 'redirect_to', rawurlencode( $requested ), $login_url );
            wp_redirect( $login_url );
            exit;
        }

        $this->enqueue_assets();
        $this->render_shell();
        exit;
    }

    public function enqueue_assets(): void {
        if ( ! get_query_var( 'qfd_dashboard' ) ) {
            return;
        }

        $asset_file = QFD_PLUGIN_DIR . 'assets/build/index.asset.php';
        $asset      = file_exists( $asset_file ) ? require $asset_file : [
            'dependencies' => [ 'wp-element', 'wp-api-fetch', 'wp-i18n' ],
            'version'      => QFD_VERSION,
        ];

        wp_enqueue_script(
            'qfd-dashboard',
            QFD_PLUGIN_URL . 'assets/build/index.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        wp_enqueue_style(
            'qfd-dashboard',
            QFD_PLUGIN_URL . 'assets/build/index.css',
            [],
            $asset['version']
        );

        $accounts_raw  = (string) get_option( 'qfd_selected_accounts', '[]' );
        $accounts_list = json_decode( $accounts_raw, true );

        $fy_years = $this->fy_years();

        wp_localize_script( 'qfd-dashboard', 'qfdData', [
            'restUrl'          => esc_url_raw( rest_url( 'quickfile/v1' ) ),
            'basePath'         => trailingslashit( (string) wp_parse_url( home_url( '/' . self::SLUG . '/' ), PHP_URL_PATH ) ),
            'nonce'            => wp_create_nonce( 'wp_rest' ),
            'currentYear'      => (int) date( 'Y' ),
            'fyStart'          => $fy_years[0]['from'] ?? $this->current_fy_start(),
            'fyEnd'            => $fy_years[0]['to']   ?? $this->current_fy_end(),
            'fyYears'          => $fy_years,
            'isAdmin'          => current_user_can( 'manage_options' ),
            'businessName'     => sanitize_text_field( (string) get_option( 'qfd_business_name', '' ) ),
            'selectedAccounts' => is_array( $accounts_list ) ? $accounts_list : [],
        ] );
    }

    private function render_shell(): void {
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php
        $name = get_option( 'qfd_business_name', '' );
        echo esc_html( $name ? $name . ' — Dashboard' : 'QuickFile Dashboard' );
    ?></title>
    <?php wp_head(); ?>
</head>
<body class="qfd-body">
    <div id="qfd-root"></div>
    <?php wp_footer(); ?>
</body>
</html><?php
    }

    private function fy_start_month(): int {
        return max( 1, min( 12, (int) get_option( 'qfd_fy_start_month', 4 ) ) );
    }

    private function current_fy_start(): string {
        $m         = $this->fy_start_month();
        $now_year  = (int) date( 'Y' );
        $now_month = (int) date( 'n' );
        $year      = ( $now_month >= $m ) ? $now_year : $now_year - 1;
        return sprintf( '%04d-%02d-01', $year, $m );
    }

    private function current_fy_end(): string {
        $m          = $this->fy_start_month();
        $now_year   = (int) date( 'Y' );
        $now_month  = (int) date( 'n' );
        $start_year = ( $now_month >= $m ) ? $now_year : $now_year - 1;
        $end_month  = $m === 1 ? 12 : $m - 1;
        $end_year   = $m === 1 ? $start_year : $start_year + 1;
        $end_day    = (int) date( 't', mktime( 0, 0, 0, $end_month, 1, $end_year ) );
        return sprintf( '%04d-%02d-%02d', $end_year, $end_month, $end_day );
    }

    private function fy_years(): array {
        $m         = $this->fy_start_month();
        $now_year  = (int) date( 'Y' );
        $now_month = (int) date( 'n' );
        $start     = ( $now_month >= $m ) ? $now_year : $now_year - 1;
        $end_month = $m === 1 ? 12 : $m - 1;
        $end_year  = $m === 1 ? $start : $start + 1;
        $end_day   = (int) date( 't', mktime( 0, 0, 0, $end_month, 1, $end_year ) );
        $label     = $start === $end_year ? (string) $start : $start . '/' . substr( (string) $end_year, 2 );
        $current   = [
            'label' => $label,
            'from'  => sprintf( '%04d-%02d-01', $start, $m ),
            'to'    => sprintf( '%04d-%02d-%02d', $end_year, $end_month, $end_day ),
        ];

        $hist_raw = (string) get_option( 'qfd_historical_years', '[]' );
        $hist     = json_decode( $hist_raw, true );
        $years    = [ $current ];
        if ( is_array( $hist ) ) {
            foreach ( $hist as $row ) {
                if ( ! empty( $row['label'] ) && ! empty( $row['from'] ) && ! empty( $row['to'] ) ) {
                    $years[] = [
                        'label' => $row['label'],
                        'from'  => $row['from'],
                        'to'    => $row['to'],
                    ];
                }
            }
        }
        return $years;
    }
}
