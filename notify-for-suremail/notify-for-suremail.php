<?php
/**
 * Plugin Name:       Notify for Suremail
 * Description:       Sends Pushover, Discord, Generic Webhook and Slack notifications when emails are blocked, fail, or succeed.
 * Tested up to:      6.9.4
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Version:           1.0.1
 * Author:            ReallyUsefulPlugins.com
 * Author URI:        https://reallyusefulplugins.com
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       notify-for-suremail
 * Website:           https://reallyusefulplugins.com
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/inc/mainwp-child.php';
require_once __DIR__ . '/inc/flowmattic.php';


define('RUP_NOTIFY_FOR_SUREMAIL_VERSION', '1.0.1');

class Suremail_Notify {
    const OPTION_KEY = 'suremail_notify_options';

    // Canonical event slugs we use internally.
    const EVT_SENT    = 'sent';
    const EVT_FAILED  = 'failed';
    const EVT_BLOCKED = 'blocked';

    public function __construct() {
        // Admin
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_styles' ] );
        add_action( 'admin_post_suremail_notify_test', [ $this, 'handle_test_action' ] );

        // Hooks from SureMails / core
        // Use a high priority so we see the final state, and explicit arg counts.
        add_action( 'suremails_mail_blocked', [ $this, 'on_mail_blocked' ], 99, 1 );
        add_action( 'wp_mail_failed',        [ $this, 'on_mail_failed'  ], 99, 1 );
        add_action( 'wp_mail_succeeded',     [ $this, 'on_mail_sent'    ], 99, 1 );
    }

    /* -----------------------------
     * Hook handlers
     * --------------------------- */

    public function on_mail_blocked( $mail_data ) {
        $this->notify_all(
            self::EVT_BLOCKED,
            'Email Blocked',
            'An outgoing email was blocked.',
            $mail_data,
            null
        );
    }

    public function on_mail_failed( $wp_error ) {
        $mail_data = $wp_error instanceof WP_Error ? $wp_error->get_error_data() : null;
        $message   = $wp_error instanceof WP_Error ? $wp_error->get_error_message() : 'Email failed to send.';
        $this->notify_all(
            self::EVT_FAILED,
            'Email Failed',
            $message,
            $mail_data,
            $wp_error
        );
    }

    public function on_mail_sent( $mail_data ) {
        $this->notify_all(
            self::EVT_SENT,
            'Email Sent',
            'An email was sent successfully.',
            $mail_data,
            null
        );
    }

    /* -----------------------------
     * Core notify fan-out
     * --------------------------- */

    private function notify_all( $event_slug, $event_title, $summary, $mail_data = null, $wp_error = null ) {
        $opts = $this->get_options();

        $payload = $this->build_payload( $event_title, $summary, $mail_data, $wp_error );

        // Respect per-channel event routing.
        if ( ! empty( $opts['enable_pushover'] ) && $this->channel_wants_event( 'pushover', $event_slug, $opts ) ) {
            $this->send_pushover( $payload, $opts );
        }

        if ( ! empty( $opts['enable_webhook'] ) && $this->channel_wants_event( 'webhook', $event_slug, $opts ) ) {
            $this->send_webhook( $payload, $opts );
        }

        if ( ! empty( $opts['enable_discord'] ) && $this->channel_wants_event( 'discord', $event_slug, $opts ) ) {
            $this->send_discord( $payload, $opts );
        }
        if ( ! empty( $opts['enable_slack'] ) && $this->channel_wants_event( 'slack', $event_slug, $opts ) ) {
            $this->send_slack( $payload, $opts );
        }
    }

    private function channel_wants_event( $channel, $event_slug, $opts ) {
        // keys like: pushover_events_sent, pushover_events_failed, pushover_events_blocked
        $key = sprintf( '%s_events_%s', $channel, $event_slug );
        return ! empty( $opts[ $key ] );
    }

    private function build_payload( $event, $summary, $mail_data, $wp_error ) {
        $opts = $this->get_options();

        // Normalize $mail_data into a tidy array
        $md = $this->normalize_mail_data( $mail_data );

        // Error detail (if any)
        $err = null;
        if ( $wp_error instanceof WP_Error ) {
            $err = [
                'code'    => $wp_error->get_error_code(),
                'message' => $wp_error->get_error_message(),
                'data'    => $wp_error->get_error_data(),
            ];
        }

        // Control inclusion/granularity
        $include_headers = ! empty( $opts['include_headers'] );
        $include_body    = ! empty( $opts['include_body'] );
        $truncate_len    = max( 0, intval( $opts['truncate_body_len'] ?? 1000 ) );

        if ( ! $include_headers ) {
            unset( $md['headers'] );
        }
        if ( ! $include_body && isset( $md['message'] ) ) {
            $md['message'] = '[message body omitted]';
        }
        if ( $include_body && isset( $md['message'] ) && $truncate_len > 0 && strlen( $md['message'] ) > $truncate_len ) {
            $md['message'] = substr( $md['message'], 0, $truncate_len ) . '… [truncated]';
        }

        $site = [
            'name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
            'url'  => home_url( '/' ),
        ];

        $payload = [
            'event'   => $event,
            'summary' => $summary,
            'site'    => $site,
            'mail'    => $md,
            'error'   => $err,
            'time'    => current_time( 'mysql' ),
        ];

        // Render compact text lines for services that prefer plain text
        $payload['title']   = sprintf( '[%s] %s', $site['name'], $event );
        $payload['message'] = $this->render_text_block( $payload );

        return $payload;
    }

    private function normalize_mail_data( $mail_data ) {
        // WordPress wp_mail usually sends array: to, subject, message, headers, attachments
        $md = [
            'to'          => null,
            'subject'     => null,
            'message'     => null,
            'headers'     => null,
            'attachments' => null,
        ];

        if ( is_array( $mail_data ) ) {
            foreach ( $md as $k => $v ) {
                if ( isset( $mail_data[ $k ] ) ) {
                    $md[ $k ] = $mail_data[ $k ];
                }
            }
            foreach ( $mail_data as $k => $v ) {
                if ( ! array_key_exists( $k, $md ) ) {
                    $md[ $k ] = $v;
                }
            }
        } elseif ( is_string( $mail_data ) ) {
            $md['message'] = $mail_data;
        } elseif ( empty( $mail_data ) ) {
            // leave defaults
        } else {
            $md['raw'] = $mail_data;
        }

        if ( isset( $md['headers'] ) && is_array( $md['headers'] ) ) {
            $md['headers'] = implode( "\n", array_map( function( $k, $v ) {
                return is_int( $k ) ? $v : "$k: $v";
            }, array_keys( $md['headers'] ), $md['headers'] ) );
        }
        if ( isset( $md['attachments'] ) && is_array( $md['attachments'] ) ) {
            $md['attachments'] = implode( ', ', $md['attachments'] );
        }
        if ( isset( $md['to'] ) && is_array( $md['to'] ) ) {
            $md['to'] = implode( ', ', $md['to'] );
        }

        return $md;
    }

    private function render_text_block( $payload ) {
        $lines   = [];
        $lines[] = $payload['summary'];
        $lines[] = 'Site: ' . $payload['site']['name'] . ' (' . $payload['site']['url'] . ')';
        $lines[] = 'When: ' . $payload['time'];

        if ( ! empty( $payload['mail']['to'] ) )      $lines[] = 'To: ' . $payload['mail']['to'];
        if ( ! empty( $payload['mail']['subject'] ) ) $lines[] = 'Subject: ' . $payload['mail']['subject'];

        if ( isset( $payload['mail']['message'] ) ) {
            $lines[] = '';
            $lines[] = 'Body:';
            $lines[] = (string) $payload['mail']['message'];
        }

        if ( ! empty( $payload['mail']['headers'] ) ) {
            $lines[] = '';
            $lines[] = 'Headers:';
            $lines[] = (string) $payload['mail']['headers'];
        }

        if ( ! empty( $payload['mail']['attachments'] ) ) {
            $lines[] = '';
            $lines[] = 'Attachments: ' . (string) $payload['mail']['attachments'];
        }

        if ( ! empty( $payload['error'] ) ) {
            $lines[] = '';
            $lines[] = 'Error:';
            if ( ! empty( $payload['error']['code'] ) )    $lines[] = 'Code: ' . $payload['error']['code'];
            if ( ! empty( $payload['error']['message'] ) ) $lines[] = 'Message: ' . $payload['error']['message'];
            if ( ! empty( $payload['error']['data'] ) )    $lines[] = 'Data: ' . wp_json_encode( $payload['error']['data'] );
        }

        return implode( "\n", $lines );
    }

    /* -----------------------------
     * Channel senders
     * --------------------------- */

    private function send_pushover( $payload, $opts ) {
        $token  = trim( (string) ( $opts['pushover_app_token'] ?? '' ) );
        $user   = trim( (string) ( $opts['pushover_user_key'] ?? '' ) );
        if ( ! $token || ! $user ) return;

        $body = [
            'token'   => $token,
            'user'    => $user,
            'title'   => $payload['title'],
            'message' => $this->truncate( $payload['message'], 1024 ), // Pushover msg limit
            'priority'=> intval( $opts['pushover_priority'] ?? 0 ),
        ];
        if ( ! empty( $opts['pushover_device'] ) ) {
            $body['device'] = sanitize_text_field( $opts['pushover_device'] );
        }

        $args = [
            'timeout' => 8,
            'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8' ],
            'body'    => $body,
        ];

        wp_remote_post( 'https://api.pushover.net/1/messages.json', $args );
    }

    private function send_discord( $payload, $opts ) {
        $url = trim( (string) ( $opts['discord_webhook_url'] ?? '' ) );
        if ( ! $url ) return;

        $content = "**" . $payload['title'] . "**\n" .
                   $payload['summary'] . "\n" .
                   "```" . $this->truncate( $payload['message'], 1900 ) . "```";

        $args = [
            'timeout' => 8,
            'headers' => [ 'Content-Type' => 'application/json; charset=UTF-8' ],
            'body'    => wp_json_encode( [ 'content' => $content ] ),
        ];

        wp_remote_post( $url, $args );
    }

    private function send_slack( $payload, $opts ) {
        $url = trim( (string) ( $opts['slack_webhook_url'] ?? '' ) );
        if ( ! $url ) return;

        $text = '*' . $payload['title'] . "*\n" . $payload['summary'] . "\n" .
                "```\n" . $this->truncate( $payload['message'], 2900 ) . "\n```";

        $args = [
            'timeout' => 8,
            'headers' => [ 'Content-Type' => 'application/json; charset=UTF-8' ],
            'body'    => wp_json_encode( [ 'text' => $text ] ),
        ];

        wp_remote_post( $url, $args );
    }



/**
     * Generic webhook sender. Posts the full payload as JSON to a configured URL.
     */
    private function send_webhook( $payload, $opts ) {
        $url = trim( (string) ( $opts['webhook_url'] ?? '' ) );
        if ( ! $url ) return;

        $args = [
            'timeout' => 8,
            'headers' => [ 'Content-Type' => 'application/json; charset=UTF-8' ],
            'body'    => wp_json_encode( $payload ),
        ];
        wp_remote_post( $url, $args );
    }

private function truncate( $text, $limit ) {
        $text = (string) $text;
        return ( strlen( $text ) > $limit ) ? substr( $text, 0, $limit - 1 ) . '…' : $text;
    }

    /* -----------------------------
     * Settings + UI
     * --------------------------- */
    public function add_settings_page() {
        add_options_page(
            'Notify for Suremail',
            'Notify for Suremail',
            'manage_options',
            'suremail-notify',
            [ $this, 'render_settings_page' ]
        );

        // Hide the menu entry but keep the page accessible directly.
        if ( $this->hide_menu() ) {
            remove_submenu_page( 'options-general.php', 'suremail-notify' );
        }
    }


    public function register_settings() {
        register_setting( 'suremail_notify', self::OPTION_KEY, [ $this, 'sanitize_options' ] );

        // Keep these registrations around (not shown with our custom UI but harmless)
        add_settings_section( 'sn_general', 'General', '__return_false', 'suremail_notify' );
        add_settings_field( 'include_headers', 'Include Headers', [ $this, 'field_checkbox' ], 'suremail_notify', 'sn_general', [ 'key' => 'include_headers' ] );
        add_settings_field( 'include_body', 'Include Message Body', [ $this, 'field_checkbox' ], 'suremail_notify', 'sn_general', [ 'key' => 'include_body' ] );
        add_settings_field( 'truncate_body_len', 'Body Truncate Length', [ $this, 'field_number' ], 'suremail_notify', 'sn_general', [ 'key' => 'truncate_body_len', 'min' => 0, 'step' => 10, 'placeholder' => '1000' ] );
    }

    public function sanitize_options( $input ) {
        $old = $this->get_options();
        $out = $old;

        $input = is_array($input) ? $input : [];

        // Helper: only update if key exists in submitted payload
        $has = function(string $k) use ($input) : bool {
            return array_key_exists($k, $input);
        };

        if ( $this->admin_locked() ) {
        // still enforce wp-config defines, but ignore all UI changes
        foreach ( $this->define_bool_map() as $opt_key => $const_name ) {
            $dv = $this->get_defined_bool($opt_key);
            if ( $dv !== null ) $out[$opt_key] = $dv;
        }
        foreach ( $this->define_map() as $opt_key => $const_name ) {
            $v = $this->get_defined_value($opt_key);
            if ( $v !== null ) $out[$opt_key] = ($opt_key === 'pushover_priority')
                ? max(-2, min(2, intval($v)))
                : $v;
        }
        return $out;
        }

        /**
        * CHECKBOXES
        *
        */
        // 1) Always enforce wp-config boolean defines (they win), even if not posted.
        foreach ( $this->define_bool_map() as $opt_key => $const_name ) {
            $dv = $this->get_defined_bool($opt_key);
            if ( $dv !== null ) {
                $out[$opt_key] = $dv;
            }
        }

        // 2) Now apply POST values for booleans that are NOT controlled by wp-config
        $bool_keys = [
            'enable_pushover', 'enable_discord', 'enable_slack',
            'include_headers', 'include_body',
            'pushover_events_sent', 'pushover_events_failed', 'pushover_events_blocked',
            'discord_events_sent',  'discord_events_failed',  'discord_events_blocked',
            'slack_events_sent',    'slack_events_failed',    'slack_events_blocked',
            'enable_webhook', 'webhook_events_sent', 'webhook_events_failed', 'webhook_events_blocked',
        ];

        foreach ( $bool_keys as $k ) {
            // If wp-config controls it, we already enforced above — ignore admin.
            if ( $this->bool_is_defined($k) ) {
                continue;
            }

            // Only update if present in the submitted payload
            if ( $has($k) ) {
                $out[$k] = ! empty($input[$k]) ? 1 : 0;
            }
        }

        // If wp-config defines exist for these, ignore admin changes
        $defined_device   = $this->get_defined_value('pushover_device');
        $defined_priority = $this->get_defined_value('pushover_priority');

        if ( $defined_device !== null ) {
            $out['pushover_device'] = $defined_device;
        }

        if ( $defined_priority !== null ) {
            $out['pushover_priority'] = max(-2, min(2, intval($defined_priority)));
        }


        // NUMBERS (only update if posted) - but not if defined
        if ( $defined_priority === null && $has('pushover_priority') ) {
            $out['pushover_priority'] = max( -2, min( 2, intval( $input['pushover_priority'] ) ) );
        }
        if ( $has('truncate_body_len') ) {
            $out['truncate_body_len'] = max( 0, intval( $input['truncate_body_len'] ) );
        }

        // NON-SECRET TEXT (only update if posted) - but not if defined
        if ( $defined_device === null && $has('pushover_device') ) {
            $out['pushover_device'] = sanitize_text_field( trim( (string) $input['pushover_device'] ) );
        }

        /**
        * SECRET FIELDS:
        * - If field is posted blank: keep old
        * - If clear flag posted for that field: clear
        * - If field posted non-blank: save new
        *
        * Only process a secret if either:
        * - the secret key itself is present in submitted form, OR
        * - its clear flag is present.
        */
        $secret_keys = [
            'pushover_app_token',
            'pushover_user_key',
            'discord_webhook_url',
            'slack_webhook_url',
            'webhook_url',
        ];

        $clear_flags = ( isset($input['__clear__']) && is_array($input['__clear__']) ) ? $input['__clear__'] : [];

        foreach ( $secret_keys as $k ) {
		    $clear_present = isset($clear_flags[$k]);
		    $key_present   = $has($k);

		    if ( ! $key_present && ! $clear_present ) {
		        continue;
		    }

		    // If a wp-config define is set for this secret, do NOT allow admin to change/clear it.
		    $defined = $this->get_defined_value( $k );
		    if ( $defined !== null ) {
		        $out[$k] = $defined;
		        continue;
		    }

		    // Optional global lock (even if not defined)
		    if ( $this->secrets_locked() ) {
		        $out[$k] = isset($old[$k]) ? (string) $old[$k] : '';
		        continue;
		    }

		    $posted   = $key_present ? trim((string) $input[$k]) : '';
		    $do_clear = $clear_present && $clear_flags[$k] === '1';

            if ( $do_clear ) {
                $out[$k] = '';
                continue;
            }

            // Blank means "unchanged" for secret inputs
            if ( $posted === '' ) {
                $out[$k] = isset($old[$k]) ? (string) $old[$k] : '';
                continue;
            }

            // Save new
            if ( str_ends_with($k, '_url') ) {
                $out[$k] = esc_url_raw( $posted );
            } else {
                $out[$k] = sanitize_text_field( $posted );
            }
        }

        // Validate URLs only if they were part of this submission OR already in out
        foreach ( [ 'discord_webhook_url', 'slack_webhook_url', 'webhook_url' ] as $url_key ) {
            if ( ! empty( $out[ $url_key ] ) && ! filter_var( $out[ $url_key ], FILTER_VALIDATE_URL ) ) {
                add_settings_error(
                    self::OPTION_KEY,
                    $url_key . '_invalid',
                    ucfirst( str_replace( '_', ' ', $url_key ) ) . ' is not a valid URL',
                    'error'
                );
                $out[ $url_key ] = '';
            }
        }

        return $out;
    }
    /** Prettier settings page with per-channel event routing + test buttons (per-card forms). */
public function render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $opts = $this->get_options();

    // Global locks / status
    $admin_locked   = $this->admin_locked();   // NEW: lock *everything* from wp-config
    $secrets_locked = $this->secrets_locked(); // existing: lock secrets only
    $has_overrides  = $this->any_wpconfig_overrides_active();
    ?>
    <div class="wrap sn-wrap">
        <h1>Notify For SureMail</h1>

        <?php
        // Banner: admin lock > secrets lock > overrides
        if ( $admin_locked || $secrets_locked || $has_overrides ): ?>
            <div class="sn-card sn-lock-banner"
                 style="border-left:4px solid <?php echo $admin_locked ? '#d63638' : ( $secrets_locked ? '#d63638' : '#2271b1' ); ?>;">
                <h2 style="margin-top:0;">
                    <?php
                    if ( $admin_locked ) {
                        echo 'Settings Locked by wp-config.php';
                    } elseif ( $secrets_locked ) {
                        echo 'Secret Editing Locked by wp-config.php';
                    } else {
                        echo 'wp-config.php Overrides Active';
                    }
                    ?>
                </h2>
                <p class="description" style="margin-bottom:0;">
                    <?php if ( $admin_locked ): ?>
                        All settings are locked by <code>SUREMAIL_NOTIFY_LOCK_ADMIN</code>. Admin changes are ignored.
                    <?php elseif ( $secrets_locked ): ?>
                        Secret editing is locked by <code>SUREMAIL_NOTIFY_LOCK_SECRETS</code>. Admin changes to secrets are ignored.
                    <?php else: ?>
                        Some settings are controlled by wp-config defines. Fields marked “wp-config.php” cannot be edited here.
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

        <?php
        // Notices
        $notice_key = 'sn_test_notice_' . get_current_user_id();
        $notice     = get_transient( $notice_key );

        if ( is_array( $notice ) ) {
            delete_transient( $notice_key );

            $ch = sanitize_key( $notice['channel'] ?? '' );
            $ev = sanitize_key( $notice['event'] ?? '' );
            $ok = ! empty( $notice['ok'] );

            $notice_class = $ok ? 'notice-success' : 'notice-warning';
            ?>
            <div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible">
                <p>
                    <?php if ( $ok ) : ?>
                        <strong>Test sent:</strong>
                    <?php else : ?>
                        <strong>Test not sent:</strong> channel disabled or missing configuration.
                    <?php endif; ?>

                    <?php echo esc_html( ucfirst( $ch ) ); ?>
                    (<?php echo esc_html( $ev ); ?>).
                </p>
            </div>
            <?php
        }
        ?>




        <div class="sn-grid">

            <!-- Overview / General -->
            <form action="options.php" method="post">
                <?php settings_fields( 'suremail_notify' ); ?>

                <?php
                $lock_include_body    = $this->bool_is_defined('include_body');
                $lock_include_headers = $this->bool_is_defined('include_headers');
                ?>

                <div class="sn-card">
                    <h2>
                        Overview
                        <span class="sn-badge <?php echo ( $opts['enable_pushover'] || $opts['enable_discord'] || $opts['enable_webhook'] || $opts['enable_slack'] ) ? 'ok' : ''; ?>">
                            <?php echo ( $opts['enable_pushover'] || $opts['enable_discord'] || $opts['enable_slack'] || $opts['enable_webhook'] ) ? 'At least one channel enabled' : 'No channels enabled'; ?>
                        </span>
                    </h2>
                    <p class="description">Choose what mail data to include and route events to channels. Keep it concise by truncating long bodies.</p>

                    <div class="sn-field sn-inline">
                        <?php if ( ! $admin_locked && ! $lock_include_body ): ?>
                            <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[include_body]" value="0">
                        <?php endif; ?>
                        <label>
                            <input
                                type="checkbox"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[include_body]"
                                value="1"
                                <?php checked( ! empty($opts['include_body']) ); ?>
                                <?php echo ( $admin_locked || $lock_include_body ) ? 'disabled="disabled"' : ''; ?>
                            >
                            Include message body
                        </label>
                        <?php if ( $lock_include_body ): ?>
                            <span class="sn-badge ok" style="white-space:nowrap;">wp-config.php</span>
                        <?php endif; ?>
                        <?php if ( $admin_locked && ! $lock_include_body ): ?>
                            <span class="sn-badge" style="white-space:nowrap;">locked</span>
                        <?php endif; ?>

                        <?php if ( ! $admin_locked && ! $lock_include_headers ): ?>
                            <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[include_headers]" value="0">
                        <?php endif; ?>
                        <label>
                            <input
                                type="checkbox"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[include_headers]"
                                value="1"
                                <?php checked( ! empty($opts['include_headers']) ); ?>
                                <?php echo ( $admin_locked || $lock_include_headers ) ? 'disabled="disabled"' : ''; ?>
                            >
                            Include headers
                        </label>
                        <?php if ( $lock_include_headers ): ?>
                            <span class="sn-badge ok" style="white-space:nowrap;">wp-config.php</span>
                        <?php endif; ?>
                        <?php if ( $admin_locked && ! $lock_include_headers ): ?>
                            <span class="sn-badge" style="white-space:nowrap;">locked</span>
                        <?php endif; ?>
                    </div>

                    <div class="sn-field">
                        <label for="truncate_body_len">Body truncate length</label>
                        <input
                            type="number"
                            class="small-text"
                            id="truncate_body_len"
                            name="<?php echo esc_attr(self::OPTION_KEY); ?>[truncate_body_len]"
                            value="<?php echo esc_attr( $opts['truncate_body_len'] ); ?>"
                            min="0"
                            step="10"
                            <?php echo $admin_locked ? 'disabled="disabled"' : ''; ?>
                        >
                        <span class="sn-small sn-muted">0 = no truncation</span>
                        <?php if ( $admin_locked ): ?>
                            <span class="sn-badge" style="white-space:nowrap;">locked</span>
                        <?php endif; ?>
                    </div>

                    <div class="sn-actions">
                        <?php if ( $admin_locked ): ?>
                            <span class="sn-small sn-muted">Settings are locked by wp-config.php</span>
                        <?php else: ?>
                            <?php submit_button( 'Save', 'primary', 'submit', false ); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <!-- Pushover -->
            <form action="options.php" method="post">
                <?php settings_fields( 'suremail_notify' ); ?>

                <?php
                $lock_enable_pushover = $this->bool_is_defined('enable_pushover');

                $lock_p_sent    = $this->bool_is_defined('pushover_events_sent');
                $lock_p_failed  = $this->bool_is_defined('pushover_events_failed');
                $lock_p_blocked = $this->bool_is_defined('pushover_events_blocked');

                $device_defined   = $this->get_defined_value('pushover_device');
                $priority_defined = $this->get_defined_value('pushover_priority');
                $is_device_defined   = ($device_defined !== null);
                $is_priority_defined = ($priority_defined !== null);
                ?>

                <div class="sn-card">
                    <h2>
                        Pushover
                        <?php if ( ! empty($opts['enable_pushover']) ) echo '<span class="sn-badge ok">Enabled</span>'; ?>
                    </h2>
                    <p class="description">Instant push to your devices via the Pushover app.</p>

                    <div class="sn-field sn-inline">
                        <?php if ( ! $admin_locked && ! $lock_enable_pushover ): ?>
                            <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_pushover]" value="0">
                        <?php endif; ?>
                        <label>
                            <input
                                type="checkbox"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_pushover]"
                                value="1"
                                <?php checked( ! empty($opts['enable_pushover']) ); ?>
                                <?php echo ( $admin_locked || $lock_enable_pushover ) ? 'disabled="disabled"' : ''; ?>
                            >
                            Enable
                        </label>
                        <?php if ( $lock_enable_pushover ): ?>
                            <span class="sn-badge ok" style="white-space:nowrap;">wp-config.php</span>
                        <?php elseif ( $admin_locked ): ?>
                            <span class="sn-badge" style="white-space:nowrap;">locked</span>
                        <?php endif; ?>
                    </div>

                        <?php
                        $this->sn_secret_input([
                            'key' => 'pushover_app_token',
                            'label' => 'App Token',
                            'type' => 'password',
                            'placeholder' => 'Enter App Token',
                        ]);
                        $this->sn_secret_input([
                            'key' => 'pushover_user_key',
                            'label' => 'User Key',
                            'type' => 'password',
                            'placeholder' => 'Enter User Key',
                        ]);
                        ?>

                        <div class="sn-inline">
                            <!-- Device -->
                            <div class="sn-field" style="flex:1">
                                <label>Device (optional)</label>
                                <?php if ( $is_device_defined ): ?>
                                    <input type="text" class="regular-text" value="<?php echo esc_attr($device_defined); ?>" disabled="disabled">
                                    <span class="sn-badge ok" style="margin-top:6px; display:inline-block;">wp-config.php</span>
                                <?php else: ?>
                                    <input
                                        type="text"
                                        class="regular-text"
                                        name="<?php echo esc_attr(self::OPTION_KEY); ?>[pushover_device]"
                                        value="<?php echo esc_attr($opts['pushover_device']); ?>"
                                        <?php echo $admin_locked ? 'disabled="disabled"' : ''; ?>
                                    >
                                    <?php if ( $admin_locked ): ?>
                                        <span class="sn-badge" style="margin-top:6px; display:inline-block;">locked</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <!-- Priority -->
                            <div class="sn-field">
                                <label>Priority</label>
                                <?php if ( $is_priority_defined ): ?>
                                    <input
                                        type="number"
                                        class="small-text"
                                        value="<?php echo esc_attr( max(-2, min(2, intval($priority_defined))) ); ?>"
                                        disabled="disabled"
                                    >
                                    <span class="sn-badge ok" style="margin-top:6px; display:inline-block;">wp-config.php</span>
                                <?php else: ?>
                                    <input
                                        type="number"
                                        class="small-text"
                                        name="<?php echo esc_attr(self::OPTION_KEY); ?>[pushover_priority]"
                                        value="<?php echo esc_attr($opts['pushover_priority']); ?>"
                                        min="-2"
                                        max="2"
                                        <?php echo $admin_locked ? 'disabled="disabled"' : ''; ?>
                                    >
                                    <?php if ( $admin_locked ): ?>
                                        <span class="sn-badge" style="margin-top:6px; display:inline-block;">locked</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="sn-sep"></div>
                        <div class="sn-field"><strong>Send notifications for:</strong></div>

                        <div class="sn-field sn-inline">
                            <?php if ( ! $admin_locked && ! $lock_p_sent ): ?>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[pushover_events_sent]" value="0">
                            <?php endif; ?>
                            <label>
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[pushover_events_sent]"
                                    value="1"
                                    <?php checked( ! empty($opts['pushover_events_sent']) ); ?>
                                    <?php echo ( $admin_locked || $lock_p_sent ) ? 'disabled="disabled"' : ''; ?>
                                >
                                Sent
                            </label>
                            <?php if ( $lock_p_sent ): ?><span class="sn-badge ok" style="white-space:nowrap;">wp-config.php</span>
                            <?php elseif ( $admin_locked ): ?><span class="sn-badge" style="white-space:nowrap;">locked</span>
                            <?php endif; ?>

                            <?php if ( ! $admin_locked && ! $lock_p_failed ): ?>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[pushover_events_failed]" value="0">
                            <?php endif; ?>
                            <label>
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[pushover_events_failed]"
                                    value="1"
                                    <?php checked( ! empty($opts['pushover_events_failed']) ); ?>
                                    <?php echo ( $admin_locked || $lock_p_failed ) ? 'disabled="disabled"' : ''; ?>
                                >
                                Failed
                            </label>
                            <?php if ( $lock_p_failed ): ?><span class="sn-badge ok" style="white-space:nowrap;">wp-config.php</span>
                            <?php elseif ( $admin_locked ): ?><span class="sn-badge" style="white-space:nowrap;">locked</span>
                            <?php endif; ?>

                            <?php if ( ! $admin_locked && ! $lock_p_blocked ): ?>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[pushover_events_blocked]" value="0">
                            <?php endif; ?>
                            <label>
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[pushover_events_blocked]"
                                    value="1"
                                    <?php checked( ! empty($opts['pushover_events_blocked']) ); ?>
                                    <?php echo ( $admin_locked || $lock_p_blocked ) ? 'disabled="disabled"' : ''; ?>
                                >
                                Blocked
                            </label>
                            <?php if ( $lock_p_blocked ): ?><span class="sn-badge ok" style="white-space:nowrap;">wp-config.php</span>
                            <?php elseif ( $admin_locked ): ?><span class="sn-badge" style="white-space:nowrap;">locked</span>
                            <?php endif; ?>
                        </div>


                    <div class="sn-actions">
                        <?php if ( $admin_locked ): ?>
                            <span class="sn-small sn-muted">Settings are locked by wp-config.php</span>
                        <?php else: ?>
                            <?php submit_button( 'Save', 'primary', 'submit', false ); ?>
                        <?php endif; ?>

                        <?php if ( ! empty( $opts['enable_pushover'] ) ): ?>
                        <div class="sn-inline">
                            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=suremail_notify_test&channel=pushover&event=sent' ), 'sn_test' ) ); ?>">Test Sent</a>
                            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=suremail_notify_test&channel=pushover&event=failed' ), 'sn_test' ) ); ?>">Test Failed</a>
                            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=suremail_notify_test&channel=pushover&event=blocked' ), 'sn_test' ) ); ?>">Test Blocked</a>
                        </div>
                        <?php else: ?>
                        <span class="sn-small sn-muted">Enable to test</span>
                        <?php endif; ?>

                    </div>
                </div>
            </form>

            <!-- Discord -->
            <form action="options.php" method="post">
                <?php settings_fields( 'suremail_notify' ); ?>

                <?php
                $lock_enable_discord = $this->bool_is_defined('enable_discord');
                $lock_d_sent    = $this->bool_is_defined('discord_events_sent');
                $lock_d_failed  = $this->bool_is_defined('discord_events_failed');
                $lock_d_blocked = $this->bool_is_defined('discord_events_blocked');
                ?>

                <div class="sn-card">
                    <h2>Discord <?php if ( ! empty($opts['enable_discord']) ) echo '<span class="sn-badge ok">Enabled</span>'; ?></h2>
                    <p class="description">Send notifications to a Discord channel via webhook.</p>

                    <div class="sn-field sn-inline">
                        <?php if ( ! $admin_locked && ! $lock_enable_discord ): ?>
                            <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_discord]" value="0">
                        <?php endif; ?>
                        <label>
                            <input
                                type="checkbox"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_discord]"
                                value="1"
                                <?php checked( ! empty($opts['enable_discord']) ); ?>
                                <?php echo ( $admin_locked || $lock_enable_discord ) ? 'disabled="disabled"' : ''; ?>
                            >
                            Enable
                        </label>
                        <?php if ( $lock_enable_discord ): ?><span class="sn-badge ok" style="white-space:nowrap;">wp-config.php</span>
                        <?php elseif ( $admin_locked ): ?><span class="sn-badge" style="white-space:nowrap;">locked</span>
                        <?php endif; ?>
                    </div>


                        <?php
                        $this->sn_secret_input([
                            'key' => 'discord_webhook_url',
                            'label' => 'Webhook URL',
                            'type' => 'password',
                            'placeholder' => 'https://discord.com/api/webhooks/...',
                        ]);
                        ?>

                        <div class="sn-sep"></div>
                        <div class="sn-field"><strong>Send notifications for:</strong></div>
                        <div class="sn-field sn-inline">
                            <?php if ( ! $admin_locked && ! $lock_d_sent ): ?>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[discord_events_sent]" value="0">
                            <?php endif; ?>
                            <label>
                                <input type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[discord_events_sent]"
                                    value="1"
                                    <?php checked( ! empty($opts['discord_events_sent']) ); ?>
                                    <?php echo ( $admin_locked || $lock_d_sent ) ? 'disabled="disabled"' : ''; ?>
                                >
                                Sent
                            </label>
                            <?php if ( $lock_d_sent ): ?><span class="sn-badge ok" style="white-space:nowrap;">wp-config.php</span>
                            <?php elseif ( $admin_locked ): ?><span class="sn-badge" style="white-space:nowrap;">locked</span>
                            <?php endif; ?>

                            <?php if ( ! $admin_locked && ! $lock_d_failed ): ?>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[discord_events_failed]" value="0">
                            <?php endif; ?>
                            <label>
                                <input type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[discord_events_failed]"
                                    value="1"
                                    <?php checked( ! empty($opts['discord_events_failed']) ); ?>
                                    <?php echo ( $admin_locked || $lock_d_failed ) ? 'disabled="disabled"' : ''; ?>
                                >
                                Failed
                            </label>
                            <?php if ( $lock_d_failed ): ?><span class="sn-badge ok" style="white-space:nowrap;">wp-config.php</span>
                            <?php elseif ( $admin_locked ): ?><span class="sn-badge" style="white-space:nowrap;">locked</span>
                            <?php endif; ?>

                            <?php if ( ! $admin_locked && ! $lock_d_blocked ): ?>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[discord_events_blocked]" value="0">
                            <?php endif; ?>
                            <label>
                                <input type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[discord_events_blocked]"
                                    value="1"
                                    <?php checked( ! empty($opts['discord_events_blocked']) ); ?>
                                    <?php echo ( $admin_locked || $lock_d_blocked ) ? 'disabled="disabled"' : ''; ?>
                                >
                                Blocked
                            </label>
                            <?php if ( $lock_d_blocked ): ?><span class="sn-badge ok" style="white-space:nowrap;">wp-config.php</span>
                            <?php elseif ( $admin_locked ): ?><span class="sn-badge" style="white-space:nowrap;">locked</span>
                            <?php endif; ?>
                        </div>


                    <div class="sn-actions">
                        <?php if ( $admin_locked ): ?>
                            <span class="sn-small sn-muted">Settings are locked by wp-config.php</span>
                        <?php else: ?>
                            <?php submit_button( 'Save', 'primary', 'submit', false ); ?>
                        <?php endif; ?>

                        <?php if ( ! empty( $opts['enable_discord'] ) ): ?>
                        <div class="sn-inline">
                            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=suremail_notify_test&channel=discord&event=sent' ), 'sn_test' ) ); ?>">Test Sent</a>
                            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=suremail_notify_test&channel=discord&event=failed' ), 'sn_test' ) ); ?>">Test Failed</a>
                            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=suremail_notify_test&channel=discord&event=blocked' ), 'sn_test' ) ); ?>">Test Blocked</a>
                        </div>
                        <?php else: ?>
                        <span class="sn-small sn-muted">Enable to test</span>
                        <?php endif; ?>

                    </div>
                </div>
            </form>

            <!-- Slack -->
            <form action="options.php" method="post">
                <?php settings_fields( 'suremail_notify' ); ?>

                <?php
                $lock_enable_slack = $this->bool_is_defined('enable_slack');
                $lock_s_sent    = $this->bool_is_defined('slack_events_sent');
                $lock_s_failed  = $this->bool_is_defined('slack_events_failed');
                $lock_s_blocked = $this->bool_is_defined('slack_events_blocked');
                ?>

                <div class="sn-card">
                    <h2>Slack <?php if ( ! empty($opts['enable_slack']) ) echo '<span class="sn-badge ok">Enabled</span>'; ?></h2>
                    <p class="description">Post to a Slack channel using an Incoming Webhook.</p>

                    <div class="sn-field sn-inline">
                        <?php if ( ! $admin_locked && ! $lock_enable_slack ): ?>
                            <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_slack]" value="0">
                        <?php endif; ?>
                        <label>
                            <input
                                type="checkbox"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_slack]"
                                value="1"
                                <?php checked( ! empty($opts['enable_slack']) ); ?>
                                <?php echo ( $admin_locked || $lock_enable_slack ) ? 'disabled="disabled"' : ''; ?>
                            >
                            Enable
                        </label>
                        <?php if ( $lock_enable_slack ): ?><span class="sn-badge ok" style="white-space:nowrap;">wp-config.php</span>
                        <?php elseif ( $admin_locked ): ?><span class="sn-badge" style="white-space:nowrap;">locked</span>
                        <?php endif; ?>
                    </div>


                        <?php
                        $this->sn_secret_input([
                            'key' => 'slack_webhook_url',
                            'label' => 'Incoming Webhook URL',
                            'type' => 'password',
                            'placeholder' => 'https://hooks.slack.com/services/...',
                        ]);
                        ?>

                        <div class="sn-sep"></div>
                        <div class="sn-field"><strong>Send notifications for:</strong></div>
                        <div class="sn-field sn-inline">
                            <?php if ( ! $admin_locked && ! $lock_s_sent ): ?>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[slack_events_sent]" value="0">
                            <?php endif; ?>
                            <label>
                                <input type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[slack_events_sent]"
                                    value="1"
                                    <?php checked( ! empty($opts['slack_events_sent']) ); ?>
                                    <?php echo ( $admin_locked || $lock_s_sent ) ? 'disabled="disabled"' : ''; ?>
                                >
                                Sent
                            </label>
                            <?php if ( $lock_s_sent ): ?><span class="sn-badge ok" style="white-space:nowrap;">wp-config.php</span>
                            <?php elseif ( $admin_locked ): ?><span class="sn-badge" style="white-space:nowrap;">locked</span>
                            <?php endif; ?>

                            <?php if ( ! $admin_locked && ! $lock_s_failed ): ?>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[slack_events_failed]" value="0">
                            <?php endif; ?>
                            <label>
                                <input type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[slack_events_failed]"
                                    value="1"
                                    <?php checked( ! empty($opts['slack_events_failed']) ); ?>
                                    <?php echo ( $admin_locked || $lock_s_failed ) ? 'disabled="disabled"' : ''; ?>
                                >
                                Failed
                            </label>
                            <?php if ( $lock_s_failed ): ?><span class="sn-badge ok" style="white-space:nowrap;">wp-config.php</span>
                            <?php elseif ( $admin_locked ): ?><span class="sn-badge" style="white-space:nowrap;">locked</span>
                            <?php endif; ?>

                            <?php if ( ! $admin_locked && ! $lock_s_blocked ): ?>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[slack_events_blocked]" value="0">
                            <?php endif; ?>
                            <label>
                                <input type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[slack_events_blocked]"
                                    value="1"
                                    <?php checked( ! empty($opts['slack_events_blocked']) ); ?>
                                    <?php echo ( $admin_locked || $lock_s_blocked ) ? 'disabled="disabled"' : ''; ?>
                                >
                                Blocked
                            </label>
                            <?php if ( $lock_s_blocked ): ?><span class="sn-badge ok" style="white-space:nowrap;">wp-config.php</span>
                            <?php elseif ( $admin_locked ): ?><span class="sn-badge" style="white-space:nowrap;">locked</span>
                            <?php endif; ?>
                        </div>


                    <div class="sn-actions">
                        <?php if ( $admin_locked ): ?>
                            <span class="sn-small sn-muted">Settings are locked by wp-config.php</span>
                        <?php else: ?>
                            <?php submit_button( 'Save', 'primary', 'submit', false ); ?>
                        <?php endif; ?>

                        <?php if ( ! empty( $opts['enable_slack'] ) ): ?>
                        <div class="sn-inline">
                            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=suremail_notify_test&channel=slack&event=sent' ), 'sn_test' ) ); ?>">Test Sent</a>
                            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=suremail_notify_test&channel=slack&event=failed' ), 'sn_test' ) ); ?>">Test Failed</a>
                            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=suremail_notify_test&channel=slack&event=blocked' ), 'sn_test' ) ); ?>">Test Blocked</a>
                        </div>
                        <?php else: ?>
                        <span class="sn-small sn-muted">Enable to test</span>
                        <?php endif; ?>

                    </div>
                </div>
            </form>

            <!-- Webhook -->
            <form action="options.php" method="post">
                <?php settings_fields( 'suremail_notify' ); ?>

                <?php
                $lock_enable_webhook = $this->bool_is_defined('enable_webhook');
                $lock_w_sent    = $this->bool_is_defined('webhook_events_sent');
                $lock_w_failed  = $this->bool_is_defined('webhook_events_failed');
                $lock_w_blocked = $this->bool_is_defined('webhook_events_blocked');
                ?>

                <div class="sn-card">
                    <h2>Webhook <?php if ( ! empty($opts['enable_webhook']) ) echo '<span class="sn-badge ok">Enabled</span>'; ?></h2>
                    <p class="description">Post to a Webhook channel using an Incoming Webhook.</p>

                    <div class="sn-field sn-inline">
                        <?php if ( ! $admin_locked && ! $lock_enable_webhook ): ?>
                            <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_webhook]" value="0">
                        <?php endif; ?>
                        <label>
                            <input
                                type="checkbox"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_webhook]"
                                value="1"
                                <?php checked( ! empty($opts['enable_webhook']) ); ?>
                                <?php echo ( $admin_locked || $lock_enable_webhook ) ? 'disabled="disabled"' : ''; ?>
                            >
                            Enable
                        </label>
                        <?php if ( $lock_enable_webhook ): ?><span class="sn-badge ok" style="white-space:nowrap;">wp-config.php</span>
                        <?php elseif ( $admin_locked ): ?><span class="sn-badge" style="white-space:nowrap;">locked</span>
                        <?php endif; ?>
                    </div>


                        <?php
                        $this->sn_secret_input([
                            'key' => 'webhook_url',
                            'label' => 'Webhook URL',
                            'type' => 'password',
                            'placeholder' => 'https://example.com/webhook-endpoint',
                        ]);
                        ?>

                        <div class="sn-sep"></div>
                        <div class="sn-field"><strong>Send notifications for:</strong></div>
                        <div class="sn-field sn-inline">
                            <?php if ( ! $admin_locked && ! $lock_w_sent ): ?>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[webhook_events_sent]" value="0">
                            <?php endif; ?>
                            <label>
                                <input type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[webhook_events_sent]"
                                    value="1"
                                    <?php checked( ! empty($opts['webhook_events_sent']) ); ?>
                                    <?php echo ( $admin_locked || $lock_w_sent ) ? 'disabled="disabled"' : ''; ?>
                                >
                                Sent
                            </label>
                            <?php if ( $lock_w_sent ): ?><span class="sn-badge ok" style="white-space:nowrap;">wp-config.php</span>
                            <?php elseif ( $admin_locked ): ?><span class="sn-badge" style="white-space:nowrap;">locked</span>
                            <?php endif; ?>

                            <?php if ( ! $admin_locked && ! $lock_w_failed ): ?>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[webhook_events_failed]" value="0">
                            <?php endif; ?>
                            <label>
                                <input type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[webhook_events_failed]"
                                    value="1"
                                    <?php checked( ! empty($opts['webhook_events_failed']) ); ?>
                                    <?php echo ( $admin_locked || $lock_w_failed ) ? 'disabled="disabled"' : ''; ?>
                                >
                                Failed
                            </label>
                            <?php if ( $lock_w_failed ): ?><span class="sn-badge ok" style="white-space:nowrap;">wp-config.php</span>
                            <?php elseif ( $admin_locked ): ?><span class="sn-badge" style="white-space:nowrap;">locked</span>
                            <?php endif; ?>

                            <?php if ( ! $admin_locked && ! $lock_w_blocked ): ?>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION_KEY); ?>[webhook_events_blocked]" value="0">
                            <?php endif; ?>
                            <label>
                                <input type="checkbox"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[webhook_events_blocked]"
                                    value="1"
                                    <?php checked( ! empty($opts['webhook_events_blocked']) ); ?>
                                    <?php echo ( $admin_locked || $lock_w_blocked ) ? 'disabled="disabled"' : ''; ?>
                                >
                                Blocked
                            </label>
                            <?php if ( $lock_w_blocked ): ?><span class="sn-badge ok" style="white-space:nowrap;">wp-config.php</span>
                            <?php elseif ( $admin_locked ): ?><span class="sn-badge" style="white-space:nowrap;">locked</span>
                            <?php endif; ?>
                        </div>


                    <div class="sn-actions">
                        <?php if ( $admin_locked ): ?>
                            <span class="sn-small sn-muted">Settings are locked by wp-config.php</span>
                        <?php else: ?>
                            <?php submit_button( 'Save', 'primary', 'submit', false ); ?>
                        <?php endif; ?>

                        <?php if ( ! empty( $opts['enable_webhook'] ) ): ?>
                        <div class="sn-inline">
                            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=suremail_notify_test&channel=webhook&event=sent' ), 'sn_test' ) ); ?>">Test Sent</a>
                            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=suremail_notify_test&channel=webhook&event=failed' ), 'sn_test' ) ); ?>">Test Failed</a>
                            <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=suremail_notify_test&channel=webhook&event=blocked' ), 'sn_test' ) ); ?>">Test Blocked</a>
                        </div>
                        <?php else: ?>
                        <span class="sn-small sn-muted">Enable to test</span>
                        <?php endif; ?>

                    </div>
                </div>
            </form>

        </div><!-- /sn-grid -->

        <div class="sn-sep"></div>
        <p class="sn-small sn-muted">
            Listens for <code>suremails_mail_blocked</code>, <code>wp_mail_failed</code>, and <code>wp_mail_succeeded</code>.
            Routes each event to your chosen channels and includes error data when available.
        </p>

    </div>

    <script>
(function() {

  function closest(el, sel) {
    while (el && el !== document) {
      if (el.matches && el.matches(sel)) return el;
      el = el.parentNode;
    }
    return null;
  }

  function getToggleForInput(input) {
    var wrap = input.closest('[data-sn-secret-wrap]');
    if (!wrap) return null;
    return wrap.querySelector('[data-sn-toggle-secret]');
  }

  function updateToggleVisibility(input) {
    var btn = getToggleForInput(input);
    if (!btn) return;

    // Show ONLY if user typed/pasted something (not on focus)
    var hasTyped = (input.value && input.value.length > 0);

    btn.style.display = hasTyped ? '' : 'none';

    // If hiding, reset back to password mode
    if (!hasTyped) {
      input.type = 'password';
      btn.textContent = 'Show';
    }
  }

  // Show/hide toggle click
  document.addEventListener('click', function(e) {
    var tbtn = e.target.closest && e.target.closest('[data-sn-toggle-secret]');
    if (!tbtn) return;

    var sel = tbtn.getAttribute('data-sn-target');
    var input = document.querySelector(sel);
    if (!input) return;

    if (input.type === 'password') {
      input.type = 'text';
      tbtn.textContent = 'Hide';
    } else {
      input.type = 'password';
      tbtn.textContent = 'Show';
    }
  });

  // Clear button click (RESTORED)
  document.addEventListener('click', function(e) {
    var btn = e.target.closest && e.target.closest('[data-sn-clear-btn]');
    if (!btn) return;

    var inputSel = btn.getAttribute('data-sn-target');
    var input = document.querySelector(inputSel);
    if (!input) return;

    // Clear the field and mark as cleared
    input.value = '';
    input.placeholder = 'Cleared (save to apply)';
    input.setAttribute('data-sn-has-saved', '0');

    var wrap = closest(input, '[data-sn-secret-wrap]');
    if (wrap) {
      var flag = wrap.querySelector('[data-sn-clear-flag-for="' + input.id + '"]');
      if (flag) flag.value = '1';

      var badge = wrap.querySelector('[data-sn-saved-badge]');
      if (badge) badge.remove();

      // Remove the Clear button itself (matches your original behavior)
      btn.remove();
    }

    // Make sure Show button hides again after clearing
    updateToggleVisibility(input);
  });

  // Input typing logic
  document.addEventListener('input', function(e) {
    var input = e.target;
    if (input && input.matches && input.matches('[data-sn-secret-input]')) {
      updateToggleVisibility(input);
    }
  });

  // Optional: init on page load
  document.querySelectorAll('[data-sn-secret-input]').forEach(updateToggleVisibility);

})();
</script>


    <?php
}



    /**
    * Secret input UI (never prints actual secret value).
    * - If wp-config define is present -> locked (disabled)
    * - If LOCK_ADMIN -> locked (disabled) + no clear button
    * - If LOCK_SECRETS -> locked (disabled) + no clear button
    */
    private function sn_secret_input( array $args ) {
        $opts   = $this->get_options();
        $key    = $args['key'];
        $label  = $args['label'] ?? '';
        $type   = $args['type'] ?? 'text';
        $ph     = $args['placeholder'] ?? '';
        $desc   = $args['description'] ?? '';

        $val   = isset($opts[$key]) ? (string) $opts[$key] : '';
        $saved = strlen(trim($val)) > 0;

        // wp-config.php override (if present)
        $defined    = $this->get_defined_value( $key );
        $is_defined = ($defined !== null);

        // Locks
        $admin_locked   = $this->admin_locked();
        $secrets_locked = $this->secrets_locked();

        // A secret is editable only if:
        // - not defined in wp-config
        // - admin is not locked
        // - secrets are not locked
        $is_locked = $is_defined || $admin_locked || $secrets_locked;

        $name = esc_attr(self::OPTION_KEY) . '[' . esc_attr($key) . ']';
        $id   = 'sn_' . esc_attr($key);

        // Never print the real value into HTML.
        $display_value = '';

        // Placeholder shows where it comes from
        if ( $is_defined ) {
            $display_ph = $this->sn_obfuscate_for_ui($defined) . ' (wp-config.php)';
        } elseif ( $saved ) {
            $display_ph = $this->sn_obfuscate_for_ui($val) . ' (saved)';
        } else {
            $display_ph = $ph;
        }
        ?>
        <div class="sn-field sn-secret" data-sn-secret-wrap>
            <?php if ($label): ?>
                <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
            <?php endif; ?>

            <div class="sn-secret-row" style="display:flex; gap:8px; align-items:center;">
                <input
                    id="<?php echo esc_attr($id); ?>"
                    type="password"
                    class="regular-text"
                    name="<?php echo esc_attr($name); ?>"
                    value=""
                    placeholder="<?php echo esc_attr($display_ph); ?>"
                    autocomplete="off"
                    data-sn-secret-input
                    data-sn-has-saved="<?php echo $saved ? '1' : '0'; ?>"
                    <?php echo $is_locked ? 'disabled="disabled"' : ''; ?>
                />

                <?php if ( ! $is_locked ): ?>
                    <button
                        type="button"
                        class="button button-secondary"
                        data-sn-toggle-secret
                        data-sn-target="#<?php echo esc_attr($id); ?>"
                        aria-label="Show or hide secret"
                        style="white-space:nowrap; display:none;"
                    >
                        Show
                    </button>
                <?php endif; ?>


                <?php if ($is_defined): ?>
                    <span class="sn-badge ok" style="white-space:nowrap;">wp-config.php</span>
                <?php elseif ($admin_locked): ?>
                    <span class="sn-badge" style="white-space:nowrap;">locked</span>
                <?php elseif ($secrets_locked): ?>
                    <span class="sn-badge" style="white-space:nowrap;">locked</span>
                <?php elseif ($saved): ?>
                    <span class="sn-badge ok sn-saved-badge" data-sn-saved-badge style="white-space:nowrap;">Saved</span>

                    <button
                        type="button"
                        class="button button-secondary sn-clear-btn"
                        data-sn-clear-btn
                        data-sn-target="#<?php echo esc_attr($id); ?>"
                        style="white-space:nowrap;"
                    >
                        Clear
                    </button>
                <?php endif; ?>
            </div>

            <?php if ($desc): ?>
                <p class="description"><?php echo esc_html($desc); ?></p>
            <?php endif; ?>

            <?php if ( ! $is_locked ): ?>
                <!-- Hidden flag so sanitize callback can detect explicit clearing -->
                <input
                    type="hidden"
                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[__clear__][<?php echo esc_attr($key); ?>]"
                    value="0"
                    data-sn-clear-flag-for="<?php echo esc_attr($id); ?>"
                />
            <?php endif; ?>

        </div>
        <?php
    }


    /** Obfuscate a secret for UI only (never store this). */
    private function sn_obfuscate_for_ui( string $value ) : string {
        $value = trim($value);
        if ($value === '') return '';
        $len = strlen($value);

        // Show last 4 chars when long enough, otherwise just bullets.
        $tail = $len >= 8 ? substr($value, -4) : '';
        return $tail ? ('••••••••' . $tail) : '••••••••';
    }










    /** Small CSS to make the page friendlier, grey “cards” */
    public function enqueue_admin_styles( $hook ) {
        if ( $hook !== 'settings_page_suremail-notify' ) { return; }

        $css = '
        .sn-wrap { max-width: 1100px; }
        .sn-grid { display: grid; grid-template-columns: 1fr; gap: 16px; }
        @media (min-width: 900px){ .sn-grid { grid-template-columns: 1fr 1fr; } }
        .sn-card { background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 8px; padding: 16px 18px; }
        .sn-card h2 { margin: 0 0 8px; font-size: 18px; }
        .sn-card p.description { margin: 6px 0 14px; color:#50575e; }
        .sn-badge { display:inline-block; padding:2px 8px; border-radius:999px; background:#dcdcde; color:#2c3338; font-size:11px; margin-left:8px; }
        .sn-badge.ok { background:#e1f5ea; color:#106c4a; }
        .sn-actions { display:flex; gap:8px; align-items:center; margin-top:10px; flex-wrap: wrap; }
        .sn-muted { color:#646970; }
        .sn-inline { display:flex; gap:10px; align-items:center; flex-wrap: wrap; }
        .sn-field { margin:8px 0; }
        .sn-field label { display:block; font-weight:600; margin-bottom:4px; }
        .sn-small { font-size:12px; color:#646970; }
        .sn-sep { height:1px; background:#dcdcde; margin:12px -18px; }
        .sn-lock-banner { margin: 18px 0 !important; padding: 14px 16px !important; border-radius: 8px; }
        ';
        wp_register_style( 'sn-admin', false );
        wp_enqueue_style( 'sn-admin' );
        wp_add_inline_style( 'sn-admin', $css );
    }

    /** Handles the Test buttons for each channel + event */
    public function handle_test_action() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Permission denied.' ); }
        check_admin_referer( 'sn_test' );

        $channel = isset( $_GET['channel'] ) ? sanitize_key( $_GET['channel'] ) : '';
        $event   = isset( $_GET['event'] ) ? sanitize_key( $_GET['event'] ) : self::EVT_SENT;

        // Build a synthetic payload that looks like a real one.
        $title   = ($event === self::EVT_FAILED) ? 'Email Failed' : (($event === self::EVT_BLOCKED) ? 'Email Blocked' : 'Email Sent');
        $summary = ($event === self::EVT_FAILED) ? 'This is a test "failed" notification.' : (($event === self::EVT_BLOCKED) ? 'This is a test "blocked" notification.' : 'This is a test "sent" notification.');

        $payload = $this->build_payload(
            $title,
            $summary,
            [
                'to'      => 'test@example.com',
                'subject' => 'Suremail Notify — test message',
                'message' => 'Hello! If you can read this, your channel is wired up correctly.',
                'headers' => [ 'X-Test' => '1' ],
            ],
            ($event === self::EVT_FAILED) ? new WP_Error( 'wp_mail_failed', 'Simulated failure', [ 'smtp_code' => '450', 'smtp_detail' => 'Mailbox busy' ] ) : null
        );

        // Respect routing for the event being tested.
        $opts = $this->get_options();
        $did_send = false;
        if ( $channel === 'pushover' && ! empty( $opts['enable_pushover'] ) ) {
            $token = trim((string)($opts['pushover_app_token'] ?? ''));
            $user  = trim((string)($opts['pushover_user_key'] ?? ''));
            if ( $token && $user ) {
                $this->send_pushover( $payload, $opts );
                $did_send = true;
            }

        } elseif ( $channel === 'discord' && ! empty( $opts['enable_discord'] ) ) {
            $url = trim((string)($opts['discord_webhook_url'] ?? ''));
            if ( $url ) {
                $this->send_discord( $payload, $opts );
                $did_send = true;
            }

        } elseif ( $channel === 'slack' && ! empty( $opts['enable_slack'] ) ) {
            $url = trim((string)($opts['slack_webhook_url'] ?? ''));
            if ( $url ) {
                $this->send_slack( $payload, $opts );
                $did_send = true;
            }

        } elseif ( $channel === 'webhook' && ! empty( $opts['enable_webhook'] ) ) {
            $url = trim((string)($opts['webhook_url'] ?? ''));
            if ( $url ) {
                $this->send_webhook( $payload, $opts );
                $did_send = true;
            }
        }

        set_transient(
            'sn_test_notice_' . get_current_user_id(),
            [ 'channel' => $channel, 'event' => $event, 'ok' => $did_send ? 1 : 0 ],
            30
        );



        // Redirect to clean URL
        wp_safe_redirect( admin_url( 'options-general.php?page=suremail-notify' ) );
        exit;

    }

    /* -----------------------------
     * Options
     * --------------------------- */

    private function get_options() {
        $defaults = [
                        'enable_webhook'    => 0,
            'webhook_events_sent'    => 0,
            'webhook_events_failed'  => 1,
            'webhook_events_blocked' => 1,
// toggles
            'enable_pushover'   => 0,
            'enable_discord'    => 0,
            'enable_slack'      => 0,
            'include_headers'   => 0,
            'include_body'      => 1,
            'truncate_body_len' => 1000,

            // pushover
            'pushover_app_token' => '',
            'pushover_user_key'  => '',
            'pushover_device'    => '',
            'pushover_priority'  => 0,

            // discord / slack
            'discord_webhook_url' => '',
            'slack_webhook_url'   => '',
            'webhook_url'       => '',

            // per-channel event routing (default: only Failed + Blocked; Sent off by default to be quieter)
            'pushover_events_sent'    => 0,
            'pushover_events_failed'  => 1,
            'pushover_events_blocked' => 1,

            'discord_events_sent'     => 0,
            'discord_events_failed'   => 1,
            'discord_events_blocked'  => 1,

            'slack_events_sent'       => 0,
            'slack_events_failed'     => 1,
            'slack_events_blocked'    => 1,
        ];

        $stored = get_option( self::OPTION_KEY, [] );
	    if ( ! is_array( $stored ) ) $stored = [];

	    $opts = array_merge( $defaults, $stored );

	    // Overlay wp-config defines (these win)
        foreach ( $this->define_map() as $opt_key => $const_name ) {
            if ( defined($const_name) ) {
                $raw = constant($const_name);

                // Priority is numeric and must be clamped
                if ( $opt_key === 'pushover_priority' ) {
                    $opts[$opt_key] = max(-2, min(2, intval($raw)));
                    continue;
                }

                // Everything else: trimmed string
                $v = trim((string) $raw);
                if ( $v !== '' ) {
                    $opts[$opt_key] = $v;
                }
            }
        }
        // Overlay wp-config boolean defines (these win)
        foreach ( $this->define_bool_map() as $opt_key => $const_name ) {
            $dv = $this->get_defined_bool($opt_key);
            if ( $dv !== null ) {
                $opts[$opt_key] = $dv;
            }
        }



	    return $opts;
	}

/** Map option keys -> wp-config define names */
private function define_map() : array {
    return [
        'pushover_app_token'  => 'SUREMAIL_NOTIFY_PUSHOVER_APP_TOKEN',
        'pushover_user_key'   => 'SUREMAIL_NOTIFY_PUSHOVER_USER_KEY',
        'pushover_device'     => 'SUREMAIL_NOTIFY_PUSHOVER_DEVICE',
        'pushover_priority'   => 'SUREMAIL_NOTIFY_PUSHOVER_PRIORITY',
        'discord_webhook_url' => 'SUREMAIL_NOTIFY_DISCORD_WEBHOOK_URL',
        'slack_webhook_url'   => 'SUREMAIL_NOTIFY_SLACK_WEBHOOK_URL',
        'webhook_url'         => 'SUREMAIL_NOTIFY_WEBHOOK_URL',
    ];
}

/** Map boolean option keys -> wp-config define names */
private function define_bool_map() : array {
    return [
        // Per-channel routing
        'pushover_events_sent'    => 'SUREMAIL_NOTIFY_PUSHOVER_EVENTS_SENT',
        'pushover_events_failed'  => 'SUREMAIL_NOTIFY_PUSHOVER_EVENTS_FAILED',
        'pushover_events_blocked' => 'SUREMAIL_NOTIFY_PUSHOVER_EVENTS_BLOCKED',

        'discord_events_sent'     => 'SUREMAIL_NOTIFY_DISCORD_EVENTS_SENT',
        'discord_events_failed'   => 'SUREMAIL_NOTIFY_DISCORD_EVENTS_FAILED',
        'discord_events_blocked'  => 'SUREMAIL_NOTIFY_DISCORD_EVENTS_BLOCKED',

        'slack_events_sent'       => 'SUREMAIL_NOTIFY_SLACK_EVENTS_SENT',
        'slack_events_failed'     => 'SUREMAIL_NOTIFY_SLACK_EVENTS_FAILED',
        'slack_events_blocked'    => 'SUREMAIL_NOTIFY_SLACK_EVENTS_BLOCKED',

        'webhook_events_sent'     => 'SUREMAIL_NOTIFY_WEBHOOK_EVENTS_SENT',
        'webhook_events_failed'   => 'SUREMAIL_NOTIFY_WEBHOOK_EVENTS_FAILED',
        'webhook_events_blocked'  => 'SUREMAIL_NOTIFY_WEBHOOK_EVENTS_BLOCKED',

        // Channel enables (optional)
        'enable_pushover' => 'SUREMAIL_NOTIFY_ENABLE_PUSHOVER',
        'enable_discord'  => 'SUREMAIL_NOTIFY_ENABLE_DISCORD',
        'enable_slack'    => 'SUREMAIL_NOTIFY_ENABLE_SLACK',
        'enable_webhook'  => 'SUREMAIL_NOTIFY_ENABLE_WEBHOOK',

        // Global include options (optional)
        'include_body'    => 'SUREMAIL_NOTIFY_INCLUDE_BODY',
        'include_headers' => 'SUREMAIL_NOTIFY_INCLUDE_HEADERS',
    ];
}

/**
 * Read a boolean define. Returns:
 * - 1 or 0 if defined
 * - null if not defined
 *
 * Accepts true/false, 1/0, "1"/"0", "true"/"false", "yes"/"no", "on"/"off"
 */
private function get_defined_bool( string $option_key ) : ?int {
    $map = $this->define_bool_map();
    if ( empty($map[$option_key]) ) return null;

    $const = $map[$option_key];
    if ( ! defined($const) ) return null;

    $raw = constant($const);

    // Normalize to string for parsing
    if (is_bool($raw)) return $raw ? 1 : 0;
    if (is_int($raw))  return $raw ? 1 : 0;

    $val = strtolower(trim((string)$raw));
    if ($val === '') return null;

    $truthy = ['1','true','yes','y','on'];
    $falsy  = ['0','false','no','n','off'];

    if (in_array($val, $truthy, true)) return 1;
    if (in_array($val, $falsy, true))  return 0;

    // fallback: PHP truthiness
    return $raw ? 1 : 0;
}

private function admin_locked() : bool {
    return defined('SUREMAIL_NOTIFY_LOCK_ADMIN') && (bool) SUREMAIL_NOTIFY_LOCK_ADMIN;
}

/** True if this boolean option is controlled by wp-config */
private function bool_is_defined( string $option_key ) : bool {
    return $this->get_defined_bool($option_key) !== null;
}


/** Return a define value if set & non-empty, otherwise null */
private function get_defined_value( string $option_key ) : ?string {
    $map = $this->define_map();
    if ( empty($map[$option_key]) ) return null;

    $const = $map[$option_key];
    if ( defined($const) ) {
        $val = trim((string) constant($const));
        return ($val !== '') ? $val : null;
    }
    return null;
}

/** Whether secrets should be locked (UI + saving) */
private function secrets_locked() : bool {
    return defined('SUREMAIL_NOTIFY_LOCK_SECRETS') && (bool) SUREMAIL_NOTIFY_LOCK_SECRETS;
}

private function any_wpconfig_overrides_active() : bool {
    // Any secret/text/number defines?
    foreach ( $this->define_map() as $opt_key => $const_name ) {
        if ( defined($const_name) ) {
            $raw = constant($const_name);

            // numeric priority can be 0 and still be meaningful
            if ( $opt_key === 'pushover_priority' ) return true;

            // other values: non-empty
            $v = trim((string) $raw);
            if ( $v !== '' ) return true;
        }
    }

    // Any bool defines?
    foreach ( $this->define_bool_map() as $opt_key => $const_name ) {
        if ( defined($const_name) ) return true;
    }

    // Global lock define?
    if ( defined('SUREMAIL_NOTIFY_LOCK_SECRETS') ) return true;

    return false;
}

private function hide_menu() : bool {
    return defined('SUREMAIL_NOTIFY_HIDE_MENU') && (bool) SUREMAIL_NOTIFY_HIDE_MENU;
}




}



new Suremail_Notify();


/** Updater bootstrap */
add_action( 'plugins_loaded', function() {
    require_once __DIR__ . '/inc/updater.php';
    $updater_config = [
    	'vendor'      => 'RUP',
        'plugin_file' => plugin_basename( __FILE__ ),
        'slug'        => 'notify-for-suremail',
        'name'        => 'Notify For SureMail',
        'version'     => RUP_NOTIFY_FOR_SUREMAIL_VERSION,
        'key'         => '',
        'server'      => 'https://raw.githubusercontent.com/stingray82/notify-for-suremail/main/uupd/index.json',
    ];
    \RUP\Updater\Updater_V2::register( $updater_config );
}, 20);

/** MainWP icon */
add_filter('mainwp_child_stats_get_plugin_info', function($info, $slug) {
    if ('notify-for-suremail/notify-for-suremail.php' === $slug) {
        $info['icon'] = 'https://raw.githubusercontent.com/stingray82/notify-for-suremail/main/uupd/icon-128.png';
    }
    return $info;
}, 10, 2);
