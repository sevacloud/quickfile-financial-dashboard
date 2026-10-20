<?php
defined( 'ABSPATH' ) || exit;

class QFD_Settings {

    private const PAGE_SLUG    = 'quickfile-dashboard';
    private const OPTION_GROUP = 'qfd_settings';

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_post_qfd_flush_cache', [ $this, 'handle_flush_cache' ] );
        add_action( 'wp_ajax_qfd_test_connection', [ $this, 'handle_test_connection' ] );
        add_action( 'wp_ajax_qfd_get_accounts',    [ $this, 'handle_get_accounts' ] );
        add_action( 'admin_post_qfd_clear_log',   [ $this, 'handle_clear_log' ] );
    }

    public function add_menu_page(): void {
        add_options_page(
            __( 'QuickFile Dashboard', 'quickfile-dashboard' ),
            __( 'QuickFile Dashboard', 'quickfile-dashboard' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function register_settings(): void {
        $options = [
            'qfd_business_name'      => 'sanitize_text_field',
            'qfd_fy_start_month'     => 'absint',
            'qfd_endpoint'           => 'esc_url_raw',
            'qfd_cache_duration'     => 'absint',
            'qfd_selected_accounts'  => [ $this, 'sanitize_selected_accounts' ],
        ];

        foreach ( $options as $name => $sanitize ) {
            register_setting( self::OPTION_GROUP, $name, [ 'sanitize_callback' => $sanitize ] );
        }

        // API token handled separately (encrypted).
        register_setting( self::OPTION_GROUP, 'qfd_api_token', [
            'sanitize_callback' => [ $this, 'sanitize_api_token' ],
        ] );

        // Historical years stored as JSON array.
        register_setting( self::OPTION_GROUP, 'qfd_historical_years', [
            'sanitize_callback' => [ $this, 'sanitize_historical_years' ],
        ] );

        add_settings_section( 'qfd_general', __( 'General', 'quickfile-dashboard' ), '__return_false', self::PAGE_SLUG );
        add_settings_section( 'qfd_api', __( 'QuickFile API', 'quickfile-dashboard' ), '__return_false', self::PAGE_SLUG );
        add_settings_section( 'qfd_accounts', '', [ $this, 'render_accounts_section_header' ], self::PAGE_SLUG );
        add_settings_section( 'qfd_cache', __( 'Cache Settings', 'quickfile-dashboard' ), '__return_false', self::PAGE_SLUG );

        $this->add_field( 'qfd_general', 'qfd_business_name', __( 'Business Name', 'quickfile-dashboard' ), 'text', __( 'Displayed in the dashboard header and browser tab.', 'quickfile-dashboard' ) );

        add_settings_field(
            'qfd_fy_start_month',
            __( 'Financial Year Start Month', 'quickfile-dashboard' ),
            [ $this, 'render_fy_month_field' ],
            self::PAGE_SLUG,
            'qfd_general'
        );

        $this->add_field( 'qfd_api', 'qfd_endpoint', __( 'API Base URL', 'quickfile-dashboard' ), 'url', sprintf( __( 'QuickFile REST API base URL. Leave blank for the default: %s', 'quickfile-dashboard' ), QFD_API::DEFAULT_ENDPOINT ) );
        $this->add_field( 'qfd_api', 'qfd_api_token', __( 'API Token', 'quickfile-dashboard' ), 'password', __( 'Personal token from QuickFile → Account Settings → Third Party Integration → API. Grant read access to Banks, Reports, Invoices, Purchases and Projects. Leave blank to keep the current token.', 'quickfile-dashboard' ) );

        add_settings_field(
            'qfd_accounts_picker',
            __( 'Selected Accounts', 'quickfile-dashboard' ),
            [ $this, 'render_account_checklist' ],
            self::PAGE_SLUG,
            'qfd_accounts'
        );

        $this->add_field( 'qfd_cache', 'qfd_cache_duration', __( 'Cache Duration (seconds)', 'quickfile-dashboard' ), 'number', __( 'Default: 900 (15 minutes).', 'quickfile-dashboard' ) );

        add_settings_section( 'qfd_historical', __( 'Historical Financial Years', 'quickfile-dashboard' ), '__return_false', self::PAGE_SLUG );
        add_settings_field(
            'qfd_historical_years',
            __( 'Prior Years', 'quickfile-dashboard' ),
            [ $this, 'render_historical_years_field' ],
            self::PAGE_SLUG,
            'qfd_historical'
        );
    }

    private function add_field( string $section, string $id, string $label, string $type, string $description = '' ): void {
        add_settings_field(
            $id,
            $label,
            function () use ( $id, $type, $description ) {
                $value = $type === 'password' ? '' : esc_attr( (string) get_option( $id, '' ) );
                printf(
                    '<input type="%s" name="%s" id="%s" value="%s" class="regular-text" autocomplete="off">',
                    esc_attr( $type ),
                    esc_attr( $id ),
                    esc_attr( $id ),
                    esc_attr( $value )
                );
                if ( $description ) {
                    echo '<p class="description">' . esc_html( $description ) . '</p>';
                }
            },
            self::PAGE_SLUG,
            $section
        );
    }

    public function render_fy_month_field(): void {
        $current = max( 1, min( 12, (int) get_option( 'qfd_fy_start_month', 4 ) ) );
        $months  = [
            1 => 'January',  2 => 'February', 3 => 'March',    4 => 'April',
            5 => 'May',      6 => 'June',      7 => 'July',     8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
        ];
        echo '<select name="qfd_fy_start_month" id="qfd_fy_start_month">';
        foreach ( $months as $num => $name ) {
            printf(
                '<option value="%d"%s>%s</option>',
                $num,
                selected( $current, $num, false ),
                esc_html( $name )
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'First month of your financial year. Default: April.', 'quickfile-dashboard' ) . '</p>';
    }


    public function sanitize_api_token( $value ): string {
        // Accept a pasted "Bearer …" value as well as the bare token.
        $trimmed = trim( preg_replace( '/^Bearer\s+/i', '', trim( (string) $value ) ) );
        if ( $trimmed !== '' ) {
            update_option( 'qfd_api_token_enc', QFD_Auth::encrypt_token( $trimmed ) );
        }
        // Only the encrypted copy is kept; never store the plain token.
        return '';
    }

    public function sanitize_selected_accounts( $value ): string {
        $str  = trim( (string) $value );
        $list = json_decode( $str, true );
        if ( ! is_array( $list ) ) {
            return '[]';
        }
        $clean = [];
        foreach ( $list as $item ) {
            if ( ! is_array( $item ) || empty( $item['nominalCode'] ) ) {
                continue;
            }
            $clean[] = [
                'bankId'      => (int) ( $item['bankId']      ?? 0 ),
                'nominalCode' => (int) ( $item['nominalCode'] ?? 0 ),
                'name'        => sanitize_text_field( $item['name'] ?? '' ),
            ];
        }
        return wp_json_encode( $clean );
    }

    public function render_historical_years_field(): void {
        $fy_month   = max( 1, min( 12, (int) get_option( 'qfd_fy_start_month', 4 ) ) );
        $saved_json = (string) get_option( 'qfd_historical_years', '[]' );
        $saved      = json_decode( $saved_json, true );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }
        ?>
        <table id="wb-hist-years-table" style="border-collapse:collapse;width:100%;max-width:600px;">
            <thead>
                <tr>
                    <th style="text-align:left;padding:4px 8px;border-bottom:1px solid #ccd0d4;"><?php esc_html_e( 'Label', 'quickfile-dashboard' ); ?></th>
                    <th style="text-align:left;padding:4px 8px;border-bottom:1px solid #ccd0d4;"><?php esc_html_e( 'From', 'quickfile-dashboard' ); ?></th>
                    <th style="text-align:left;padding:4px 8px;border-bottom:1px solid #ccd0d4;"><?php esc_html_e( 'To', 'quickfile-dashboard' ); ?></th>
                    <th style="border-bottom:1px solid #ccd0d4;"></th>
                </tr>
            </thead>
            <tbody id="wb-hist-years-tbody">
                <?php foreach ( $saved as $row ) : ?>
                    <tr style="border-bottom:1px solid #f0f0f1;">
                        <td style="padding:4px 8px;">
                            <input type="text" class="wb-hist-label" value="<?php echo esc_attr( $row['label'] ?? '' ); ?>" style="width:90px;" placeholder="2023/24">
                        </td>
                        <td style="padding:4px 8px;">
                            <input type="date" class="wb-hist-from" value="<?php echo esc_attr( $row['from'] ?? '' ); ?>" style="width:140px;">
                        </td>
                        <td style="padding:4px 8px;">
                            <input type="date" class="wb-hist-to" value="<?php echo esc_attr( $row['to'] ?? '' ); ?>" style="width:140px;">
                        </td>
                        <td style="padding:4px 8px;">
                            <button type="button" class="wb-hist-remove button-link" style="color:#d63638;font-size:1.2em;line-height:1;padding:0 4px;" title="<?php esc_attr_e( 'Remove', 'quickfile-dashboard' ); ?>">×</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <template id="wb-hist-year-tpl">
            <tr style="border-bottom:1px solid #f0f0f1;">
                <td style="padding:4px 8px;">
                    <input type="text" class="wb-hist-label" value="" style="width:90px;" placeholder="2023/24">
                </td>
                <td style="padding:4px 8px;">
                    <input type="date" class="wb-hist-from" value="" style="width:140px;">
                </td>
                <td style="padding:4px 8px;">
                    <input type="date" class="wb-hist-to" value="" style="width:140px;">
                </td>
                <td style="padding:4px 8px;">
                    <button type="button" class="wb-hist-remove button-link" style="color:#d63638;font-size:1.2em;line-height:1;padding:0 4px;" title="<?php esc_attr_e( 'Remove', 'quickfile-dashboard' ); ?>">×</button>
                </td>
            </tr>
        </template>

        <p style="margin-top:8px;">
            <button type="button" id="wb-hist-add-year" class="button">＋ <?php esc_html_e( 'Add Year', 'quickfile-dashboard' ); ?></button>
        </p>
        <p class="description"><?php esc_html_e( 'Add an entry for each prior financial year. These years will appear in the dashboard period selector and closing balances will be retrieved automatically from QuickFile.', 'quickfile-dashboard' ); ?></p>

        <input type="hidden" name="qfd_historical_years" id="wb-hist-years-input" value="<?php echo esc_attr( $saved_json ); ?>">

        <script>
        (function() {
            var fyMonth  = <?php echo (int) $fy_month; ?>;
            var tbody    = document.getElementById('wb-hist-years-tbody');
            var tpl      = document.getElementById('wb-hist-year-tpl');
            var addBtn   = document.getElementById('wb-hist-add-year');
            var hiddenIn = document.getElementById('wb-hist-years-input');
            var form     = document.querySelector('form[action="options.php"]');

            function daysInMonth(y, m) {
                return new Date(y, m, 0).getDate();
            }

            function autoFillDates(row) {
                var labelIn = row.querySelector('.wb-hist-label');
                var fromIn  = row.querySelector('.wb-hist-from');
                var toIn    = row.querySelector('.wb-hist-to');
                if (!labelIn || !fromIn || !toIn) return;
                var match = labelIn.value.match(/^(\d{4})\/(\d{2})$/);
                if (!match) return;
                var startYear = parseInt(match[1], 10);
                var endYear   = startYear + 1;
                var endMonth  = fyMonth === 1 ? 12 : fyMonth - 1;
                var eYear     = fyMonth === 1 ? startYear : endYear;
                var eDay      = daysInMonth(eYear, endMonth);
                fromIn.value = startYear + '-' + String(fyMonth).padStart(2,'0') + '-01';
                toIn.value   = eYear + '-' + String(endMonth).padStart(2,'0') + '-' + String(eDay).padStart(2,'0');
            }

            function serializeTable() {
                var rows = tbody.querySelectorAll('tr');
                var data = [];
                rows.forEach(function(row) {
                    var label = (row.querySelector('.wb-hist-label') || {}).value || '';
                    var from  = (row.querySelector('.wb-hist-from')  || {}).value || '';
                    var to    = (row.querySelector('.wb-hist-to')    || {}).value || '';
                    if (!label && !from && !to) return;
                    data.push({ label: label, from: from, to: to });
                });
                hiddenIn.value = JSON.stringify(data);
            }

            function attachRowListeners(row) {
                var removeBtn = row.querySelector('.wb-hist-remove');
                if (removeBtn) {
                    removeBtn.addEventListener('click', function() {
                        row.parentNode.removeChild(row);
                        serializeTable();
                    });
                }
                var labelIn = row.querySelector('.wb-hist-label');
                if (labelIn) {
                    labelIn.addEventListener('input', function() { autoFillDates(row); serializeTable(); });
                }
                row.querySelectorAll('.wb-hist-from, .wb-hist-to').forEach(function(inp) {
                    inp.addEventListener('input', serializeTable);
                });
            }

            // Attach listeners to existing rows.
            tbody.querySelectorAll('tr').forEach(attachRowListeners);

            addBtn.addEventListener('click', function() {
                var clone = tpl.content.cloneNode(true);
                var row   = clone.querySelector('tr');
                attachRowListeners(row);
                tbody.appendChild(clone);
            });

            if (form) { form.addEventListener('submit', serializeTable); }
        })();
        </script>
        <?php
    }

    public function sanitize_historical_years( $value ): string {
        $list = json_decode( trim( (string) $value ), true );
        if ( ! is_array( $list ) ) {
            return '[]';
        }
        $clean = [];
        foreach ( $list as $row ) {
            if ( empty( $row['label'] ) || empty( $row['from'] ) || empty( $row['to'] ) ) {
                continue;
            }
            $clean[] = [
                'label' => sanitize_text_field( $row['label'] ),
                'from'  => sanitize_text_field( $row['from'] ),
                'to'    => sanitize_text_field( $row['to'] ),
            ];
        }
        return wp_json_encode( $clean );
    }

    public function handle_get_accounts(): void {
        check_ajax_referer( 'qfd_get_accounts' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorised', 403 );
        }
        $auth = new QFD_Auth();
        if ( ! $auth->is_configured() ) {
            wp_send_json_error( 'API token not configured. Save an API Token first.' );
        }
        $api      = new QFD_API();
        $accounts = $api->get_bank_accounts();
        if ( is_wp_error( $accounts ) ) {
            wp_send_json_error( $accounts->get_error_message() );
        }
        wp_send_json_success( $accounts );
    }

    public function render_accounts_section_header(): void {
        $saved = json_decode( (string) get_option( 'qfd_selected_accounts', '[]' ), true );
        $count = is_array( $saved ) ? count( $saved ) : 0;
        ?>
        <h2 style="display:flex;align-items:center;gap:8px;">
            <?php esc_html_e( 'Bank Accounts', 'quickfile-dashboard' ); ?>
            <button type="button" id="wb-load-accounts"
                    title="<?php esc_attr_e( 'Load accounts from QuickFile', 'quickfile-dashboard' ); ?>"
                    style="background:none;border:none;cursor:pointer;font-size:1em;padding:2px 4px;color:#2271b1;line-height:1;">↻</button>
            <span id="wb-accounts-status" style="font-size:.75em;font-weight:400;font-style:italic;color:#555;">
                <?php
                if ( $count > 0 ) {
                    /* translators: %d: number of accounts */
                    printf( esc_html__( '%d account(s) selected', 'quickfile-dashboard' ), $count );
                }
                ?>
            </span>
        </h2>
        <?php
    }

    public function render_account_checklist(): void {
        $saved_json = (string) get_option( 'qfd_selected_accounts', '[]' );
        $saved      = json_decode( $saved_json, true );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }
        ?>
        <div id="wb-account-checklist">
            <?php if ( ! empty( $saved ) ) : ?>
                <?php foreach ( $saved as $acc ) : ?>
                    <?php
                    $val = wp_json_encode( [
                        'bankId'      => (int) ( $acc['bankId']      ?? 0 ),
                        'nominalCode' => (int) ( $acc['nominalCode'] ?? 0 ),
                        'name'        => $acc['name'] ?? '',
                    ] );
                    ?>
                    <label style="display:block;margin-bottom:4px;">
                        <input type="checkbox" class="wb-account-cb" value="<?php echo esc_attr( $val ); ?>" checked>
                        <?php echo esc_html( $acc['name'] ?? '' ); ?>
                        <span style="color:#888;font-size:.8em;margin-left:6px;">ID: <?php echo esc_html( (string) ( $acc['bankId'] ?? '' ) ); ?></span>
                    </label>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <p class="description" style="margin-top:6px;">
            <?php esc_html_e( 'Click ↻ next to "Bank Accounts" above to load or refresh the account list from QuickFile, then tick the accounts to include.', 'quickfile-dashboard' ); ?>
        </p>
        <input type="hidden" name="qfd_selected_accounts" id="wb-selected-accounts-input"
               value="<?php echo esc_attr( $saved_json ); ?>">
        <script>
        (function() {
            var nonce    = '<?php echo esc_js( wp_create_nonce( 'qfd_get_accounts' ) ); ?>';
            var hiddenIn = document.getElementById('wb-selected-accounts-input');
            var checklist= document.getElementById('wb-account-checklist');
            var statusEl = document.getElementById('wb-accounts-status');
            var loadBtn  = document.getElementById('wb-load-accounts');
            var form     = document.querySelector('form[action="options.php"]');

            function updateHiddenInput() {
                var selected = [];
                checklist.querySelectorAll('.wb-account-cb:checked').forEach(function(cb) {
                    try { selected.push(JSON.parse(cb.value)); } catch(e) {}
                });
                hiddenIn.value = JSON.stringify(selected);
                if ( statusEl ) {
                    statusEl.textContent = selected.length > 0
                        ? selected.length + ' <?php echo esc_js( __( 'account(s) selected', 'quickfile-dashboard' ) ); ?>'
                        : '';
                }
            }

            checklist.addEventListener('change', updateHiddenInput);
            if ( form ) { form.addEventListener('submit', updateHiddenInput); }

            if ( loadBtn ) {
                loadBtn.addEventListener('click', function() {
                    loadBtn.disabled = true;
                    if ( statusEl ) { statusEl.style.color = '#555'; statusEl.textContent = '<?php echo esc_js( __( 'Loading…', 'quickfile-dashboard' ) ); ?>'; }

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ action: 'qfd_get_accounts', _ajax_nonce: nonce })
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        if ( ! resp.success ) {
                            if ( statusEl ) { statusEl.style.color = '#d63638'; statusEl.textContent = '✗ ' + (resp.data || 'Unknown error'); }
                            return;
                        }
                        var accounts = resp.data;

                        // Remember which nominalCodes are currently checked.
                        var checkedCodes = {};
                        checklist.querySelectorAll('.wb-account-cb:checked').forEach(function(cb) {
                            try { var d = JSON.parse(cb.value); if (d && d.nominalCode) checkedCodes[d.nominalCode] = true; } catch(e) {}
                        });

                        // Rebuild checklist with all accounts from QuickFile.
                        checklist.innerHTML = '';
                        accounts.forEach(function(acc) {
                            var val   = JSON.stringify({ bankId: acc.BankId, nominalCode: acc.NominalCode, name: acc.Name });
                            var label = document.createElement('label');
                            label.style.cssText = 'display:block;margin-bottom:4px;';
                            var cb = document.createElement('input');
                            cb.type = 'checkbox';
                            cb.className = 'wb-account-cb';
                            cb.value = val;
                            if ( checkedCodes[acc.NominalCode] ) { cb.checked = true; }
                            var sub = document.createElement('span');
                            sub.style.cssText = 'color:#888;font-size:.8em;margin-left:6px;';
                            sub.textContent = 'ID: ' + acc.BankId;
                            label.appendChild(cb);
                            label.appendChild(document.createTextNode(' ' + acc.Name + ' (' + acc.BankType + ')'));
                            label.appendChild(sub);
                            checklist.appendChild(label);
                        });

                        updateHiddenInput();
                        if ( statusEl ) { statusEl.style.color = '#00a32a'; statusEl.textContent = '✓ <?php echo esc_js( __( 'Loaded — tick the accounts to include, then Save Changes.', 'quickfile-dashboard' ) ); ?>'; }
                    })
                    .catch(function(e) {
                        if ( statusEl ) { statusEl.style.color = '#d63638'; statusEl.textContent = '✗ Request failed: ' + e.message; }
                    })
                    .finally(function() { loadBtn.disabled = false; });
                });
            }
        })();
        </script>
        <?php
    }

    public function handle_test_connection(): void {
        check_ajax_referer( 'qfd_test_connection' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorised', 403 );
        }

        $auth = new QFD_Auth();
        if ( ! $auth->is_configured() ) {
            wp_send_json_error( 'API token not configured. Save an API Token first.' );
        }

        $api  = new QFD_API();
        wp_send_json_success( $api->diagnostic_request() );
    }

    public function handle_clear_log(): void {
        check_admin_referer( 'qfd_clear_log' );
        if ( current_user_can( 'manage_options' ) ) {
            QFD_API::clear_log();
        }
        wp_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG ], admin_url( 'options-general.php' ) ) );
        exit;
    }

    public function handle_flush_cache(): void {
        check_admin_referer( 'qfd_flush_cache' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorised', 'quickfile-dashboard' ) );
        }
        global $wpdb;
        $prefix  = $wpdb->esc_like( '_transient_' . QFD_API::CACHE_PREFIX ) . '%';
        $timeout = $wpdb->esc_like( '_transient_timeout_' . QFD_API::CACHE_PREFIX ) . '%';
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $prefix,
            $timeout
        ) );
        wp_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'cache_flushed' => '1' ], admin_url( 'options-general.php' ) ) );
        exit;
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $api_configured = ( new QFD_Auth() )->is_configured();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'QuickFile Dashboard Settings', 'quickfile-dashboard' ); ?></h1>

            <?php if ( isset( $_GET['cache_flushed'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Cache cleared successfully.', 'quickfile-dashboard' ); ?></p></div>
            <?php endif; ?>

            <?php settings_errors( self::OPTION_GROUP ); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( self::PAGE_SLUG );
                submit_button();
                ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'API Status', 'quickfile-dashboard' ); ?></h2>
            <p>
                <?php if ( $api_configured ) : ?>
                    <span style="color:green;">&#10003; <?php esc_html_e( 'API token is configured.', 'quickfile-dashboard' ); ?></span>
                <?php else : ?>
                    <span style="color:red;">&#10007; <?php esc_html_e( 'API token is not configured. Enter an API Token above.', 'quickfile-dashboard' ); ?></span>
                <?php endif; ?>
            </p>
            <?php if ( $api_configured ) : ?>
            <p>
                <button type="button" id="wb-test-connection" class="button button-secondary">
                    <?php esc_html_e( 'Test Connection', 'quickfile-dashboard' ); ?>
                </button>
            </p>
            <div id="wb-test-result" style="margin-top:12px;padding:12px;background:#f6f7f7;border:1px solid #ddd;border-radius:4px;font-family:monospace;font-size:13px;white-space:pre-wrap;display:none;max-height:300px;overflow:auto;"></div>
            <script>
            document.getElementById('wb-test-connection').addEventListener('click', function() {
                var btn = this;
                var out = document.getElementById('wb-test-result');
                btn.disabled = true;
                btn.textContent = '<?php echo esc_js( __( 'Testing…', 'quickfile-dashboard' ) ); ?>';
                out.style.display = 'none';
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'qfd_test_connection',
                        _ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'qfd_test_connection' ) ); ?>'
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    out.style.display = 'block';
                    if (data.success) {
                        out.style.borderColor = '#00a32a';
                        out.style.background  = '#f0fff4';
                        out.textContent = '✓ Connection successful:\n\n' + JSON.stringify(data.data, null, 2);
                    } else {
                        out.style.borderColor = '#d63638';
                        out.style.background  = '#fff0f0';
                        out.textContent = '✗ ' + (data.data || 'Unknown error');
                    }
                })
                .catch(function(e) {
                    out.style.display  = 'block';
                    out.style.background = '#fff0f0';
                    out.textContent    = '✗ Request failed: ' + e.message;
                })
                .finally(function() {
                    btn.disabled = false;
                    btn.textContent = '<?php echo esc_js( __( 'Test Connection', 'quickfile-dashboard' ) ); ?>';
                });
            });
            </script>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Cache Management', 'quickfile-dashboard' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'qfd_flush_cache' ); ?>
                <input type="hidden" name="action" value="qfd_flush_cache">
                <?php submit_button( __( 'Flush Cache Now', 'quickfile-dashboard' ), 'secondary' ); ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Dashboard', 'quickfile-dashboard' ); ?></h2>
            <p>
                <?php
                $dashboard_url = home_url( '/quickfile-dashboard/' );
                $login_url     = home_url( '/' . QFD_Login::SLUG . '/' );
                printf(
                    '<a href="%s" class="button button-primary" style="margin-right:8px;">%s</a>',
                    esc_url( $dashboard_url ),
                    esc_html__( 'Open Dashboard', 'quickfile-dashboard' )
                );
                printf(
                    '<a href="%s" class="button button-secondary">%s</a>',
                    esc_url( $login_url ),
                    esc_html__( 'Open Login Page', 'quickfile-dashboard' )
                );
                ?>
                <p class="description" style="margin-top:8px;">
                    <?php
                    printf(
                        /* translators: %s: login page URL */
                        esc_html__( 'Login URL: %s', 'quickfile-dashboard' ),
                        '<code>' . esc_html( $login_url ) . '</code>'
                    );
                    ?>
                </p>
            </p>
            <hr>
            <h2>
                <label>
                    <input type="checkbox" id="wb-log-toggle" style="margin-right:6px;">
                    <?php esc_html_e( 'API Debug Log', 'quickfile-dashboard' ); ?>
                </label>
            </h2>
            <div id="wb-log-wrap" style="display:none;">
                <?php
                $log = QFD_API::get_log();
                if ( empty( $log ) ) :
                ?>
                    <p><em><?php esc_html_e( 'No log entries yet. Use Test Connection or load the dashboard to generate entries.', 'quickfile-dashboard' ); ?></em></p>
                <?php else : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px;">
                        <?php wp_nonce_field( 'qfd_clear_log' ); ?>
                        <input type="hidden" name="action" value="qfd_clear_log">
                        <?php submit_button( __( 'Clear Log', 'quickfile-dashboard' ), 'secondary small', 'submit', false ); ?>
                    </form>
                    <table class="widefat striped" style="font-family:monospace;font-size:12px;">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Time', 'quickfile-dashboard' ); ?></th>
                                <th><?php esc_html_e( 'Method', 'quickfile-dashboard' ); ?></th>
                                <th><?php esc_html_e( 'URL', 'quickfile-dashboard' ); ?></th>
                                <th><?php esc_html_e( 'HTTP', 'quickfile-dashboard' ); ?></th>
                                <th><?php esc_html_e( 'Response', 'quickfile-dashboard' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( array_reverse( $log ) as $entry ) : ?>
                            <tr>
                                <td style="white-space:nowrap;"><?php echo esc_html( $entry['time'] ?? '' ); ?></td>
                                <td style="white-space:nowrap;"><?php echo esc_html( $entry['method'] ?? '' ); ?></td>
                                <td style="word-break:break-all;"><?php echo esc_html( $entry['url'] ?? '' ); ?></td>
                                <td style="font-weight:bold;color:<?php echo ( ( $entry['http_code'] ?? 0 ) === 200 ) ? 'green' : 'red'; ?>">
                                    <?php echo esc_html( $entry['http_code'] ?? '' ); ?>
                                </td>
                                <td style="max-width:400px;overflow:hidden;word-break:break-all;">
                                    <?php echo esc_html( $entry['response'] ?? '' ); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <script>
            document.getElementById('wb-log-toggle').addEventListener('change', function() {
                document.getElementById('wb-log-wrap').style.display = this.checked ? 'block' : 'none';
            });
            </script>
        </div>
        <?php
    }
}
