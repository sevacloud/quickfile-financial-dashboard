<?php
defined( 'ABSPATH' ) || exit;

/**
 * One-time migration from Wincobank Dashboard option keys to QuickFile Dashboard keys.
 *
 * Called on plugins_loaded before any other plugin classes initialise.
 * Safe to run on every request — bails immediately after the first successful run.
 */
class QFD_Migrator {

    /**
     * Run the migration. Idempotent — returns immediately if already done.
     */
    public static function run(): void {
        self::migrate_v1();
        self::migrate_v2_rest_api();
    }

    /**
     * QuickFile JSON API (v1.2) → REST API (v2).
     *
     * The v2 API authenticates with a single bearer token, so the legacy
     * account number / application ID / API key are removed, and a saved
     * legacy endpoint is cleared so the v2 default applies. Cached v1-shaped
     * responses are flushed. Old qfd_budget_{TagID} options are left untouched;
     * v2 tags have no ID, so budgets are now stored by name in qfd_project_budgets.
     */
    private static function migrate_v2_rest_api(): void {
        if ( get_option( 'qfd_migrated_v2' ) ) {
            return;
        }

        if ( str_contains( (string) get_option( 'qfd_endpoint', '' ), 'api.quickfile.co.uk/1_' ) ) {
            delete_option( 'qfd_endpoint' );
        }

        foreach ( [ 'qfd_account_number', 'qfd_application_id', 'qfd_api_key', 'qfd_api_key_enc' ] as $legacy ) {
            delete_option( $legacy );
        }

        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like( '_transient_' . QFD_API::CACHE_PREFIX ) . '%',
            $wpdb->esc_like( '_transient_timeout_' . QFD_API::CACHE_PREFIX ) . '%'
        ) );

        update_option( 'qfd_migrated_v2', 1 );
    }

    private static function migrate_v1(): void {
        if ( get_option( 'qfd_migrated_v1' ) ) {
            return;
        }

        // -----------------------------------------------------------------
        // 1. Simple option key renames
        // -----------------------------------------------------------------

        $option_map = [
            'wincobank_business_name'     => 'qfd_business_name',
            'wincobank_qf_endpoint'       => 'qfd_endpoint',
            'wincobank_qf_account_number' => 'qfd_account_number',
            'wincobank_qf_application_id' => 'qfd_application_id',
            'wincobank_qf_api_key'        => 'qfd_api_key',
            'wincobank_qf_api_key_enc'    => 'qfd_api_key_enc',
            'wincobank_cache_duration'    => 'qfd_cache_duration',
            'wincobank_selected_accounts' => 'qfd_selected_accounts',
            'wincobank_api_log'           => 'qfd_api_log',
        ];

        foreach ( $option_map as $old_key => $new_key ) {
            $old_value = get_option( $old_key, null );
            if ( $old_value !== null && get_option( $new_key, null ) === null ) {
                add_option( $new_key, $old_value );
            }
            if ( $old_value !== null ) {
                delete_option( $old_key );
            }
        }

        // -----------------------------------------------------------------
        // 2. Wildcard option migration: wincobank_prior_year_* and wincobank_budget_*
        // -----------------------------------------------------------------

        global $wpdb;

        $wildcard_prefixes = [
            'wincobank_prior_year_' => 'qfd_prior_year_',
            'wincobank_budget_'     => 'qfd_budget_',
        ];

        foreach ( $wildcard_prefixes as $old_prefix => $new_prefix ) {
            $like = $wpdb->esc_like( $old_prefix ) . '%';
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $like
                )
            );

            if ( ! $rows ) {
                continue;
            }

            foreach ( $rows as $row ) {
                $suffix   = substr( $row->option_name, strlen( $old_prefix ) );
                $new_name = $new_prefix . $suffix;

                if ( get_option( $new_name, null ) === null ) {
                    add_option( $new_name, $row->option_value );
                }
                delete_option( $row->option_name );
            }
        }

        // -----------------------------------------------------------------
        // 3. Role migration: wincobank_dashboard_access → qfd_access
        // -----------------------------------------------------------------

        $old_role_slug = 'wincobank_dashboard_access';
        $new_role_slug = 'qfd_access';

        $old_role = get_role( $old_role_slug );
        $new_role = get_role( $new_role_slug );

        if ( $old_role && ! $new_role ) {
            add_role( $new_role_slug, 'QuickFile Dashboard Access', $old_role->capabilities );

            // Re-assign users who held the old role.
            $users = get_users( [ 'role' => $old_role_slug ] );
            foreach ( $users as $user ) {
                $user->remove_role( $old_role_slug );
                $user->add_role( $new_role_slug );
            }

            // Grant the capability to administrators if not already present.
            $admin_role = get_role( 'administrator' );
            if ( $admin_role && ! $admin_role->has_cap( $new_role_slug ) ) {
                $admin_role->add_cap( $new_role_slug );
            }

            remove_role( $old_role_slug );
        }

        // Also remove the old cap from administrators if it still lingers.
        $admin_role = get_role( 'administrator' );
        if ( $admin_role && $admin_role->has_cap( $old_role_slug ) ) {
            $admin_role->remove_cap( $old_role_slug );
        }

        // -----------------------------------------------------------------
        // 4. Mark migration as complete
        // -----------------------------------------------------------------

        update_option( 'qfd_migrated_v1', 1 );
    }
}
