<?php
defined( 'ABSPATH' ) || exit;

/**
 * Custom login page for the QuickFile Dashboard plugin.
 *
 * Registers /quickfile-login/ as a virtual WordPress page so the plugin
 * works even when wp-login.php has been blocked. All authentication is
 * handled through wp_signon() — no custom credential storage.
 */
class QFD_Login {

    public const SLUG = 'quickfile-login';

    public function init(): void {
        add_action( 'init',              [ $this, 'add_rewrite_rule' ] );
        add_filter( 'query_vars',        [ $this, 'add_query_var'    ] );
        add_action( 'template_redirect', [ $this, 'intercept_request' ] );
    }

    public function add_rewrite_rule(): void {
        add_rewrite_rule(
            '^' . self::SLUG . '(/.*)?$',
            'index.php?qfd_login=1',
            'top'
        );
    }

    public function add_query_var( array $vars ): array {
        $vars[] = 'qfd_login';
        return $vars;
    }

    public function intercept_request(): void {
        if ( ! get_query_var( 'qfd_login' ) ) {
            return;
        }

        // Already authenticated — bounce straight to the dashboard.
        if ( QFD_Roles::current_user_is_trustee() ) {
            wp_redirect( $this->safe_redirect_url() );
            exit;
        }

        if ( isset( $_SERVER['REQUEST_METHOD'] ) && strtoupper( $_SERVER['REQUEST_METHOD'] ) === 'POST' ) {
            $this->handle_login();
        } else {
            $this->render_page();
        }
        exit;
    }

    // -----------------------------------------------------------------
    // Authentication
    // -----------------------------------------------------------------

    private function handle_login(): void {
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'qfd_login' ) ) {
            $this->render_page( __( 'Security check failed. Please try again.', 'quickfile-dashboard' ) );
            return;
        }

        $username = sanitize_user( wp_unslash( $_POST['log'] ?? '' ) );
        $password = wp_unslash( $_POST['pwd'] ?? '' );

        if ( $username === '' || $password === '' ) {
            $this->render_page( __( 'Please enter your username and password.', 'quickfile-dashboard' ) );
            return;
        }

        $user = wp_signon(
            [
                'user_login'    => $username,
                'user_password' => $password,
                'remember'      => ! empty( $_POST['rememberme'] ),
            ],
            is_ssl()
        );

        if ( is_wp_error( $user ) ) {
            // Generic message — avoids leaking whether the username exists.
            $this->render_page( __( 'Incorrect username or password.', 'quickfile-dashboard' ) );
            return;
        }

        // Ensure the authenticated user actually has dashboard access.
        if ( ! $user->has_cap( QFD_Roles::ROLE_SLUG ) && ! user_can( $user, 'manage_options' ) ) {
            wp_logout();
            $this->render_page( __( 'You do not have permission to access the dashboard.', 'quickfile-dashboard' ) );
            return;
        }

        wp_redirect( $this->safe_redirect_url() );
        exit;
    }

    private function safe_redirect_url(): string {
        $redirect = isset( $_REQUEST['redirect_to'] )
            ? wp_sanitize_redirect( wp_unslash( $_REQUEST['redirect_to'] ) )
            : '';

        // wp_validate_redirect rejects off-site URLs.
        if ( $redirect === '' || ! wp_validate_redirect( $redirect, '' ) ) {
            $redirect = home_url( '/quickfile-dashboard/' );
        }

        return $redirect;
    }

    // -----------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------

    private function render_page( string $error = '' ): void {
        $raw_name = (string) get_option( 'qfd_business_name', '' );
        $business = esc_html( $raw_name !== '' ? $raw_name : __( 'Financial Dashboard', 'quickfile-dashboard' ) );
        $nonce    = wp_create_nonce( 'qfd_login' );
        $redirect = esc_attr( $this->safe_redirect_url() );
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo $business; ?> — <?php esc_html_e( 'Sign In', 'quickfile-dashboard' ); ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#EEF2F7;min-height:100vh;display:flex;align-items:center;justify-content:center}
.wb-lw{width:100%;max-width:420px;padding:24px}
.wb-lb{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(27,58,107,.15);overflow:hidden}
.wb-lh{background:#1B3A6B;color:#fff;padding:32px;text-align:center}
.wb-lh h1{font-size:1.35rem;font-weight:700;letter-spacing:-.01em}
.wb-lh p{opacity:.75;margin-top:6px;font-size:.875rem}
.wb-lb-body{padding:28px 32px 32px}
.wb-err{background:#fee2e2;color:#b91c1c;border-left:4px solid #b91c1c;border-radius:4px;padding:10px 14px;margin-bottom:20px;font-size:.875rem}
.wb-lf label{display:block;font-size:.8125rem;font-weight:600;color:#1B3A6B;margin:16px 0 5px;letter-spacing:.02em;text-transform:uppercase}
.wb-lf input[type=text],
.wb-lf input[type=password]{width:100%;padding:10px 13px;border:1.5px solid #c8d0da;border-radius:7px;font-size:1rem;transition:border-color .15s,box-shadow .15s;outline:none}
.wb-lf input[type=text]:focus,
.wb-lf input[type=password]:focus{border-color:#1B3A6B;box-shadow:0 0 0 3px rgba(27,58,107,.1)}
.wb-rem{display:flex;align-items:center;gap:8px;margin-top:16px;font-size:.875rem;color:#555e6b;cursor:pointer}
.wb-rem input{margin:0;cursor:pointer}
.wb-lf button{width:100%;background:#1B3A6B;color:#fff;border:none;border-radius:7px;padding:12px;font-size:1rem;font-weight:600;cursor:pointer;margin-top:24px;transition:background .15s}
.wb-lf button:hover{background:#0D6E6E}
.wb-lf button:active{background:#154e98}
</style>
</head>
<body>
<div class="wb-lw">
    <div class="wb-lb">
        <div class="wb-lh">
            <h1><?php echo $business; ?></h1>
            <p><?php esc_html_e( 'Finance Dashboard', 'quickfile-dashboard' ); ?></p>
        </div>
        <div class="wb-lb-body">
            <?php if ( $error !== '' ) : ?>
                <div class="wb-err" role="alert"><?php echo esc_html( $error ); ?></div>
            <?php endif; ?>
            <form method="post" class="wb-lf" autocomplete="on">
                <input type="hidden" name="_wpnonce"    value="<?php echo esc_attr( $nonce ); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo $redirect; ?>">

                <label for="log"><?php esc_html_e( 'Username', 'quickfile-dashboard' ); ?></label>
                <input type="text" name="log" id="log"
                       autocomplete="username" autofocus
                       value="<?php echo esc_attr( sanitize_user( wp_unslash( $_POST['log'] ?? '' ) ) ); ?>">

                <label for="pwd"><?php esc_html_e( 'Password', 'quickfile-dashboard' ); ?></label>
                <input type="password" name="pwd" id="pwd" autocomplete="current-password">

                <label class="wb-rem">
                    <input type="checkbox" name="rememberme" value="1"<?php checked( ! empty( $_POST['rememberme'] ) ); ?>>
                    <?php esc_html_e( 'Remember me', 'quickfile-dashboard' ); ?>
                </label>

                <button type="submit"><?php esc_html_e( 'Sign In', 'quickfile-dashboard' ); ?></button>
            </form>
        </div>
    </div>
</div>
</body>
</html><?php
    }
}
