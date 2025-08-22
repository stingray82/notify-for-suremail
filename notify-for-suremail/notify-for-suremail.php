<?php
/**
 * Plugin Name:       Notify for Suremail
 * Description:       Sends Pushover, Discord, Generic Webhook and Slack notifications when emails are blocked, fail, or succeed.
 * Tested up to:      6.8.2
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Version:           0.9.6
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


define('RUP_NOTIFY_FOR_SUREMAIL_VERSION', '0.9.6');

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
            'Suremail Notify',
            'Suremail Notify',
            'manage_options',
            'suremail-notify',
            [ $this, 'render_settings_page' ]
        );
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
        $out = $this->get_options();

        // toggles
        $bool_keys = [
            'enable_pushover', 'enable_discord', 'enable_slack',
            'include_headers', 'include_body',

            // per-channel event routing
            'pushover_events_sent', 'pushover_events_failed', 'pushover_events_blocked',
            'discord_events_sent',  'discord_events_failed',  'discord_events_blocked',
            'slack_events_sent',    'slack_events_failed',    'slack_events_blocked',
            'enable_webhook', 'webhook_events_sent', 'webhook_events_failed', 'webhook_events_blocked',
        ];
        foreach ( $bool_keys as $k ) {
            $out[ $k ] = ! empty( $input[ $k ] ) ? 1 : 0;
        }

        // text fields
        $text_keys = [ 'pushover_app_token', 'pushover_user_key', 'pushover_device', 'discord_webhook_url', 'slack_webhook_url' ];
        foreach ( $text_keys as $k ) {
            if ( isset( $input[ $k ] ) ) {
                $val       = is_string( $input[ $k ] ) ? trim( $input[ $k ] ) : '';
                $out[ $k ] = sanitize_text_field( $val );
            }
        }

        // numbers
        if ( isset( $input['pushover_priority'] ) ) {
            $out['pushover_priority'] = max( -2, min( 2, intval( $input['pushover_priority'] ) ) );
        }
        if ( isset( $input['truncate_body_len'] ) ) {
            $out['truncate_body_len'] = max( 0, intval( $input['truncate_body_len'] ) );
        }

        $out['webhook_url'] = isset( $input['webhook_url'] )
            ? esc_url_raw( trim( (string) $input['webhook_url'] ) )
            : ( $out['webhook_url'] ?? '' );


        // URL specific sanitization
        foreach ( [ 'discord_webhook_url', 'slack_webhook_url', 'webhook_url' ] as $url_key ) {
            if ( ! empty( $out[ $url_key ] ) && ! filter_var( $out[ $url_key ], FILTER_VALIDATE_URL ) ) {
                add_settings_error( self::OPTION_KEY, $url_key . '_invalid', ucfirst( str_replace( '_', ' ', $url_key ) ) . ' is not a valid URL', 'error' );
                $out[ $url_key ] = '';
            }
        }

        return $out;
    }

    /** Prettier settings page with per-channel event routing + test buttons */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $opts = $this->get_options();
        ?>
        <div class="wrap sn-wrap">
            <h1>Suremail Notify</h1>

            <?php if ( isset($_GET['sn_test']) && $_GET['sn_test'] == '1' ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Test sent:</strong> <?php echo esc_html( ucfirst( sanitize_key( $_GET['sn_channel'] ) ) ); ?> (<?php echo esc_html( sanitize_key( $_GET['sn_event'] ?? '' ) ); ?>).</p>
                </div>
            <?php endif; ?>

            <form action="options.php" method="post">
                <?php settings_fields( 'suremail_notify' ); ?>

                <div class="sn-grid">

                    <!-- Overview / General -->
                    <div class="sn-card">
                        <h2>
                            Overview
                            <span class="sn-badge <?php echo ( $opts['enable_pushover'] || $opts['enable_discord'] || $opts['enable_webhook'] || $opts['enable_slack'] ) ? 'ok' : ''; ?>">
                                <?php echo ( $opts['enable_pushover'] || $opts['enable_discord'] || $opts['enable_slack'] || $opts['enable_webhook'] ) ? 'At least one channel enabled' : 'No channels enabled'; ?>
                            </span>
                        </h2>
                        <p class="description">Choose what mail data to include and route events to channels. Keep it concise by truncating long bodies.</p>

                        <div class="sn-field sn-inline">
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[include_body]" value="1" <?php checked( ! empty($opts['include_body']) ); ?>> Include message body</label>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[include_headers]" value="1" <?php checked( ! empty($opts['include_headers']) ); ?>> Include headers</label>
                        </div>

                        <div class="sn-field">
                            <label for="truncate_body_len">Body truncate length</label>
                            <input type="number" class="small-text" id="truncate_body_len"
                                name="<?php echo esc_attr(self::OPTION_KEY); ?>[truncate_body_len]"
                                value="<?php echo esc_attr( $opts['truncate_body_len'] ); ?>" min="0" step="10">
                            <span class="sn-small sn-muted">0 = no truncation</span>
                        </div>
                    </div>

                    <!-- Pushover -->
                    <div class="sn-card">
                        <h2>Pushover <?php if ( ! empty($opts['enable_pushover']) ) echo '<span class="sn-badge ok">Enabled</span>'; ?></h2>
                        <p class="description">Instant push to your devices via the Pushover app.</p>

                        <div class="sn-field sn-inline">
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_pushover]" value="1" <?php checked( ! empty($opts['enable_pushover']) ); ?>> Enable</label>
                        </div>

                        <div class="sn-field"><label>App Token</label>
                            <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[pushover_app_token]" value="<?php echo esc_attr($opts['pushover_app_token']); ?>">
                        </div>
                        <div class="sn-field"><label>User Key</label>
                            <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[pushover_user_key]" value="<?php echo esc_attr($opts['pushover_user_key']); ?>">
                        </div>

                        <div class="sn-inline">
                            <div class="sn-field" style="flex:1">
                                <label>Device (optional)</label>
                                <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[pushover_device]" value="<?php echo esc_attr($opts['pushover_device']); ?>">
                            </div>
                            <div class="sn-field">
                                <label>Priority</label>
                                <input type="number" class="small-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[pushover_priority]" value="<?php echo esc_attr($opts['pushover_priority']); ?>" min="-2" max="2">
                            </div>
                        </div>

                        <div class="sn-sep"></div>
                        <div class="sn-field"><strong>Send notifications for:</strong></div>
                        <div class="sn-field sn-inline">
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[pushover_events_sent]" value="1" <?php checked( ! empty($opts['pushover_events_sent']) ); ?>> Sent</label>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[pushover_events_failed]" value="1" <?php checked( ! empty($opts['pushover_events_failed']) ); ?>> Failed</label>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[pushover_events_blocked]" value="1" <?php checked( ! empty($opts['pushover_events_blocked']) ); ?>> Blocked</label>
                        </div>

                        <div class="sn-actions">
                            <?php submit_button( 'Save', 'primary', 'submit', false ); ?>
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

                    <!-- Discord -->
                    <div class="sn-card">
                        <h2>Discord <?php if ( ! empty($opts['enable_discord']) ) echo '<span class="sn-badge ok">Enabled</span>'; ?></h2>
                        <p class="description">Send notifications to a Discord channel via webhook.</p>

                        <div class="sn-field sn-inline">
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_discord]" value="1" <?php checked( ! empty($opts['enable_discord']) ); ?>> Enable</label>
                        </div>
                        <div class="sn-field"><label>Webhook URL</label>
                            <input type="url" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[discord_webhook_url]" placeholder="https://discord.com/api/webhooks/..." value="<?php echo esc_attr($opts['discord_webhook_url']); ?>">
                        </div>

                        <div class="sn-sep"></div>
                        <div class="sn-field"><strong>Send notifications for:</strong></div>
                        <div class="sn-field sn-inline">
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[discord_events_sent]" value="1" <?php checked( ! empty($opts['discord_events_sent']) ); ?>> Sent</label>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[discord_events_failed]" value="1" <?php checked( ! empty($opts['discord_events_failed']) ); ?>> Failed</label>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[discord_events_blocked]" value="1" <?php checked( ! empty($opts['discord_events_blocked']) ); ?>> Blocked</label>
                        </div>

                        <div class="sn-actions">
                            <?php submit_button( 'Save', 'primary', 'submit', false ); ?>
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

                    <!-- Slack -->
                    <div class="sn-card">
                        
                        
            <h2>Slack <?php if ( ! empty($opts['enable_slack']) ) echo '<span class="sn-badge ok">Enabled</span>'; ?></h2>
                        <p class="description">Post to a Slack channel using an Incoming Webhook.</p>

                        <div class="sn-field sn-inline">
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_slack]" value="1" <?php checked( ! empty($opts['enable_slack']) ); ?>> Enable</label>
                        </div>
                        <div class="sn-field"><label>Incoming Webhook URL</label>
                            <input type="url" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[slack_webhook_url]" placeholder="https://hooks.slack.com/services/..." value="<?php echo esc_attr($opts['slack_webhook_url']); ?>">
                        </div>

                        <div class="sn-sep"></div>
                        <div class="sn-field"><strong>Send notifications for:</strong></div>
                        <div class="sn-field sn-inline">
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[slack_events_sent]" value="1" <?php checked( ! empty($opts['slack_events_sent']) ); ?>> Sent</label>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[slack_events_failed]" value="1" <?php checked( ! empty($opts['slack_events_failed']) ); ?>> Failed</label>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[slack_events_blocked]" value="1" <?php checked( ! empty($opts['slack_events_blocked']) ); ?>> Blocked</label>
                        </div>

                        <div class="sn-actions">
                            <?php submit_button( 'Save', 'primary', 'submit', false ); ?>
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
                    <!-- Webhook -->
<div class="sn-card">
                        <h2>Webhook <?php if ( ! empty($opts['enable_webhook']) ) echo '<span class="sn-badge ok">Enabled</span>'; ?></h2>
                        <p class="description">Post to a Webhook channel using an Incoming Webhook.</p>

                        <div class="sn-field sn-inline">
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_webhook]" value="1" <?php checked( ! empty($opts['enable_webhook']) ); ?>> Enable</label>
                        </div>
                        <div class="sn-field"><label>Webhook URL</label>
                            <input type="url" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[webhook_url]" placeholder="https://example.com/webhook-endpoint" value="<?php echo esc_attr($opts['webhook_url']); ?>">
                        </div>

                        <div class="sn-sep"></div>
                        <div class="sn-field"><strong>Send notifications for:</strong></div>
                        <div class="sn-field sn-inline">
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[webhook_events_sent]" value="1" <?php checked( ! empty($opts['webhook_events_sent']) ); ?>> Sent</label>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[webhook_events_failed]" value="1" <?php checked( ! empty($opts['webhook_events_failed']) ); ?>> Failed</label>
                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[webhook_events_blocked]" value="1" <?php checked( ! empty($opts['webhook_events_blocked']) ); ?>> Blocked</label>
                        </div>

                        <div class="sn-actions">
                            <?php submit_button( 'Save', 'primary', 'submit', false ); ?>
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


                </div><!-- /sn-grid -->

                <div class="sn-sep"></div>
                <p class="sn-small sn-muted">
                    Listens for <code>suremails_mail_blocked</code>, <code>wp_mail_failed</code>, and <code>wp_mail_succeeded</code>.
                    Routes each event to your chosen channels and includes error data when available.
                </p>

            </form>
        </div>
        <?php
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

        if ( $channel === 'pushover' && ! empty( $opts['enable_pushover'] ) && $this->channel_wants_event( 'pushover', $event, $opts ) ) {
            $this->send_pushover( $payload, $opts );

        } elseif ( $channel === 'discord' && ! empty( $opts['enable_discord'] ) && $this->channel_wants_event( 'discord', $event, $opts ) ) {
            $this->send_discord( $payload, $opts );

        } elseif ( $channel === 'slack' && ! empty( $opts['enable_slack'] ) && $this->channel_wants_event( 'slack', $event, $opts ) ) {
            $this->send_slack( $payload, $opts );

        } elseif ( $channel === 'webhook' && ! empty( $opts['enable_webhook'] ) && $this->channel_wants_event( 'webhook', $event, $opts ) ) {
            $this->send_webhook( $payload, $opts );
        }



        wp_safe_redirect( add_query_arg(
            [ 'page' => 'suremail-notify', 'sn_test' => '1', 'sn_channel' => $channel, 'sn_event' => $event ],
            admin_url( 'options-general.php' )
        ) );
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
        return array_merge( $defaults, $stored );
    }
}

new Suremail_Notify();


/** Updater bootstrap */
add_action( 'plugins_loaded', function() {
    require_once __DIR__ . '/inc/updater.php';
    $updater_config = [
        'plugin_file' => plugin_basename( __FILE__ ),
        'slug'        => 'notify-for-suremail',
        'name'        => 'Notify For SureMail',
        'version'     => RUP_NOTIFY_FOR_SUREMAIL_VERSION,
        'key'         => '',
        'server'      => 'https://raw.githubusercontent.com/stingray82/notify-for-suremail/main/uupd/index.json',
    ];
    \RUP\Updater\Updater_V1::register( $updater_config );
}, 20);

/** MainWP icon */
add_filter('mainwp_child_stats_get_plugin_info', function($info, $slug) {
    if ('notify-for-suremail/notify-for-suremail.php' === $slug) {
        $info['icon'] = 'https://raw.githubusercontent.com/stingray82/notify-for-suremail/main/uupd/icon-128.png';
    }
    return $info;
}, 10, 2);
