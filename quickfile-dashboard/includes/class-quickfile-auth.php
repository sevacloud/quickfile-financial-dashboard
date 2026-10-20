<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles QuickFile REST API authentication.
 *
 * Uses a personal API token (QuickFile → Account Settings → Third Party
 * Integration → API), sent as "Authorization: Bearer <token>".
 * The token is stored encrypted in wp_options — never hardcoded or exposed client-side.
 */
class QFD_Auth {

    private string $token = '';

    public function __construct() {
        $this->token = $this->get_decrypted_token();
    }

    public function get_token(): string {
        return $this->token;
    }

    public function is_configured(): bool {
        return $this->token !== '';
    }

    // -----------------------------------------------------------------
    // Credential storage helpers (AES-256-CBC via openssl)
    // -----------------------------------------------------------------

    public static function encrypt_token( string $plain ): string {
        $key = self::derive_key();
        $iv  = openssl_random_pseudo_bytes( 16 );
        $enc = openssl_encrypt( $plain, 'AES-256-CBC', $key, 0, $iv );
        return base64_encode( $iv . '::' . $enc );
    }

    private function get_decrypted_token(): string {
        $stored = (string) get_option( 'qfd_api_token_enc', '' );
        if ( $stored === '' ) {
            return '';
        }
        try {
            $decoded = base64_decode( $stored, true );
            if ( $decoded === false || strpos( $decoded, '::' ) === false ) {
                return '';
            }
            [ $iv, $enc ] = explode( '::', $decoded, 2 );
            $plain = openssl_decrypt( $enc, 'AES-256-CBC', self::derive_key(), 0, $iv );
            return $plain === false ? '' : $plain;
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    private static function derive_key(): string {
        // Derive a 32-byte key from WordPress secret keys — never stored separately.
        return substr( hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY ), 0, 32 );
    }
}
