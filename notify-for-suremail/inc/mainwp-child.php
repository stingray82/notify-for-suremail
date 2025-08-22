<?php


if ( ! defined( 'ABSPATH' ) ) { exit; }

//This contains MainWP Code


/**
 * Add our payload to the normal MainWP site sync when explicitly requested.
 * Dashboard sets ['syncSureMail' => true] via its own filter.
 */
add_filter('mainwp_site_sync_others_data', function ($information, $data = []) {
    $debug = defined('WP_DEBUG') && WP_DEBUG;

    if ( ! empty( $data['syncSureMail'] ) ) {
        if ( $debug ) error_log('[SureMail][child] syncSureMail flag seen, returning payload.');
        $information['syncSureMail'] = function_exists('rup_suremail_build_payload')
            ? rup_suremail_build_payload()
            : [];
    } else {
        if ( $debug ) error_log('[SureMail][child] syncSureMail flag NOT present; skipping.');
    }
    return $information;
}, 10, 2);


/** Build payload for sync/snapshot. */
if ( ! function_exists('rup_suremail_build_payload') ) {
    function rup_suremail_build_payload() {
        global $wpdb;

        $out = [
            'activated'      => 0,
            'default_id'     => '',
            'connections'    => [],
            'log_total'      => 0,
            'recent_logs'    => [],
            'notify_active'  => 0,
            'notify_options' => [],
        ];

        // SureMails core settings (if plugin active)
        if ( class_exists('\SureMails\Inc\Settings') ) {
            $out['activated'] = 1;
            $settings         = \SureMails\Inc\Settings::instance()->get_settings();
            $out['default_id']  = (string) ( $settings['default_connection']['id'] ?? '' );
            $out['connections'] = is_array( $settings['connections'] ?? null ) ? $settings['connections'] : [];
        }

        // Recent email logs (if table exists)
        $table = $wpdb->prefix . 'suremails_email_log';
        $found = $wpdb->get_var( $wpdb->prepare('SHOW TABLES LIKE %s', $table) );
        if ( $found === $table ) {
            $out['log_total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
            $out['recent_logs'] = $wpdb->get_results(
                "SELECT id, email_to, subject, status, connection, created_at
                 FROM `$table` ORDER BY id DESC LIMIT 50",
                ARRAY_A
            );
        }

        // Notify options (if module active)
        if ( class_exists('Suremail_Notify') ) {
            $out['notify_active']  = 1;
            $opts                  = get_option( Suremail_Notify::OPTION_KEY, [] );
            $out['notify_options'] = is_array( $opts ) ? $opts : [];
        }

        return $out;
    }
}



/**
 * SureMail payload is included in normal MainWP sync when the dashboard asks for it.
 */
add_filter('mainwp_site_sync_others_data', function ($information, $data = []) {
    $debug = defined('WP_DEBUG') && WP_DEBUG;

    if (!empty($data['syncSureMail'])) {
        if ($debug) error_log('[SureMail][child] syncSureMail flag seen, returning payload.');
        $information['syncSureMail'] = function_exists('rup_suremail_build_payload') ? rup_suremail_build_payload() : [];
    } else {
        if ($debug) error_log('[SureMail][child] syncSureMail flag NOT present; skipping.');
    }
    return $information;
}, 10, 2);

/** Build payload for sync/snapshot. */
if (!function_exists('rup_suremail_build_payload')) {
    function rup_suremail_build_payload() {
        global $wpdb;

        $out = [
            'activated'      => 0,
            'default_id'     => '',
            'connections'    => [],
            'log_total'      => 0,
            'recent_logs'    => [],
            'notify_active'  => 0,
            'notify_options' => [],
        ];

        // SureMails core settings (if plugin active)
        if (class_exists('\SureMails\Inc\Settings')) {
            $out['activated'] = 1;
            $settings         = \SureMails\Inc\Settings::instance()->get_settings();
            $out['default_id']  = (string)($settings['default_connection']['id'] ?? '');
            $out['connections'] = is_array($settings['connections'] ?? null) ? $settings['connections'] : [];
        }

        // Recent email logs (if table exists)
        $table = $wpdb->prefix . 'suremails_email_log';
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($found === $table) {
            $out['log_total'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM `$table`");
            $out['recent_logs'] = $wpdb->get_results(
                "SELECT id, email_to, subject, status, connection, created_at
                 FROM `$table` ORDER BY id DESC LIMIT 50",
                ARRAY_A
            );
        }

        // Notify options (if module active)
        if (class_exists('Suremail_Notify')) {
            $out['notify_active']  = 1;
            $opts                  = get_option(Suremail_Notify::OPTION_KEY, []);
            $out['notify_options'] = is_array($opts) ? $opts : [];
        }

        return $out;
    }
}

/**
 * Robust extra_execution handler.
 * Requires our signature to avoid other plugins interfering.
 */
add_filter('mainwp_child_extra_execution', function ($information, $post) {
    $debug = defined('WP_DEBUG') && WP_DEBUG;
    $information = is_array($information) ? $information : [];

    // Defensive normalization
    $post = is_array($post) ? $post : [];
    $post = wp_unslash($post);

    // Require our signature (sent by the dashboard transport).
    $sig = isset($post['suremail_sig']) ? (string)$post['suremail_sig'] : '';
    if ($sig !== 'rup_suremail_v1') {
        // Not our payload → ignore so other handlers can still use extra_execution.
        return $information;
    }

    if ($debug) {
        $keys = implode(',', array_keys($post));
        error_log('[SureMail][child][extra] received keys: ' . $keys);
    }

    // Action may be top-level or nested; check a couple of common nests to be safe.
    $action = '';
    $pick   = function ($arr, $keys) {
        foreach ($keys as $k) if (!empty($arr[$k]) && is_string($arr[$k])) return $arr[$k];
        return '';
    };
    $action = $pick($post, ['mwp_action', 'suremail_action', 'action']);
    if ($action === '') {
        foreach (['payload','data','params'] as $nest) {
            if (isset($post[$nest]) && is_array($post[$nest])) {
                $action = $pick($post[$nest], ['mwp_action','suremail_action','action']);
                if ($action !== '') { $post = $post[$nest]; break; }
            }
        }
    }

    if ($debug) error_log('[SureMail][child][extra] action=' . $action);

    $opt_key = class_exists('Suremail_Notify') ? Suremail_Notify::OPTION_KEY : 'suremail_notify_options';

    // Health
    if ($action === 'ping') {
        $information['status'] = 'ok';
        $information['pong']   = 1;
        return $information;
    }

    // Snapshot
    if ($action === 'get_snapshot') {
        $information['status']   = 'ok';
        $information['snapshot'] = function_exists('rup_suremail_build_payload') ? rup_suremail_build_payload() : [];
        return $information;
    }

    // Test email: send one message via a specific connection ID
if ( $action === 'test_email' ) {
    $debug = defined( 'WP_DEBUG' ) && WP_DEBUG;

    $id   = isset( $post['id'] )   ? (string) $post['id']   : '';
    $to   = isset( $post['to'] )   ? sanitize_email( (string) $post['to'] )   : '';
    $from = isset( $post['from'] ) ? sanitize_email( (string) $post['from'] ) : '';

    if ( empty( $id ) || empty( $to ) || ! is_email( $to ) ) {
        $information['status']  = 'error';
        $information['message'] = 'Missing/invalid parameters.';
        return $information;
    }

    $conn = rup_suremail_find_connection_by_id( $id );
    if ( ! is_array( $conn ) ) {
        $information['status']  = 'error';
        $information['message'] = 'Unknown connection ID.';
        return $information;
    }

    $blogname = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
    $site_url = site_url();
    $now_local = wp_date( 'Y-m-d H:i:s' ); // site timezone
    $tz_string = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : ( get_option( 'timezone_string' ) ?: 'UTC' );

    // From details — always force to the connection From (dashboard sends the same, but enforce here just in case)
    $conn_from_email = (string) ( $conn['from_email'] ?? '' );
    $conn_from_name  = (string) ( $conn['from_name']  ?? $blogname );
    $send_from_email = ! empty( $from ) ? $from : $conn_from_email; // if dashboard sent one, use it; else use connection default

    // Temporarily force wp_mail() FROM so SureMails routes via this connection.
    $fn_from = function() use ( $send_from_email ) { return $send_from_email; };
    $fn_name = function() use ( $conn_from_name )  { return $conn_from_name;  };
    add_filter( 'wp_mail_from',      $fn_from, 10, 1 );
    add_filter( 'wp_mail_from_name', $fn_name, 10, 1 );

    // Build message (text/plain)
    $conn_type  = (string) ( $conn['type'] ?? '' );
    $conn_title = (string) ( $conn['connection_title'] ?? '' );

    $subject = 'SureMail Test via MainWP — ' . $blogname;
    $body = implode( "\n", [
        'This is a test email sent to verify your email connection with SureMail using your MainWP Dashboard.',
        "If you're receiving this message, your setup is working correctly!",
        '',
        'Connection',
        '  ID:              ' . $id,
        '  From Email:      ' . $conn_from_email,
        '  Connection Type: ' . $conn_type,
        '  Connection Title:' . ( $conn_title === '' ? ' (none)' : ' ' . $conn_title ),
        '',
        'Sent From',
        '  Site Name:       ' . $blogname,
        '  Site URL:        ' . $site_url,
        '  Date & Time:     ' . $now_local . ( $tz_string ? ' (' . $tz_string . ')' : '' ),
    ] );

    $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

    $sent = wp_mail( $to, $subject, $body, $headers );

    // Clean up filters so they don't affect other mail.
    remove_filter( 'wp_mail_from',      $fn_from, 10 );
    remove_filter( 'wp_mail_from_name', $fn_name, 10 );

    if ( $debug ) {
        error_log( '[SureMail][child][test_email] id=' . $id . ' to=' . $to . ' sent=' . ( $sent ? '1' : '0' ) );
    }

    if ( $sent ) {
        $information['status'] = 'sent';
        $information['details'] = [
            'used_connection_id'    => $id,
            'used_from_email'       => $send_from_email,
            'used_from_name'        => $conn_from_name,
            'connection_type'       => $conn_type,
            'connection_title'      => $conn_title,
        ];
        return $information;
    }

    $information['status']  = 'error';
    $information['message'] = 'wp_mail() returned false.';
    return $information;
}


    // Update notify options
    if ($action === 'update_notify') {
        $in  = (isset($post['options']) && is_array($post['options'])) ? $post['options'] : [];
        $cur = get_option($opt_key, []);

        $clean = [
            // General
            'include_body'        => !empty($in['include_body']) ? 1 : 0,
            'include_headers'     => !empty($in['include_headers']) ? 1 : 0,
            'truncate_body_len'   => isset($in['truncate_body_len']) ? max(0, (int)$in['truncate_body_len']) : (int)($cur['truncate_body_len'] ?? 1000),

            // Channels
            'enable_pushover'     => !empty($in['enable_pushover']) ? 1 : 0,
            'enable_discord'      => !empty($in['enable_discord'])  ? 1 : 0,
            'enable_slack'        => !empty($in['enable_slack'])    ? 1 : 0,
            'enable_webhook'      => !empty($in['enable_webhook'])  ? 1 : 0,

            // Creds / URLs
            'pushover_app_token'  => sanitize_text_field(trim((string)($in['pushover_app_token'] ?? ''))),
            'pushover_user_key'   => sanitize_text_field(trim((string)($in['pushover_user_key']  ?? ''))),
            'pushover_device'     => sanitize_text_field(trim((string)($in['pushover_device']    ?? ''))),
            'pushover_priority'   => max(-2, min(2, (int)($in['pushover_priority'] ?? 0))),

            'discord_webhook_url' => esc_url_raw(trim((string)($in['discord_webhook_url'] ?? ''))),
            'slack_webhook_url'   => esc_url_raw(trim((string)($in['slack_webhook_url']   ?? ''))),
            'webhook_url'         => esc_url_raw(trim((string)($in['webhook_url']         ?? ''))),

            // Events
            'pushover_events_sent'    => !empty($in['pushover_events_sent']) ? 1 : 0,
            'pushover_events_failed'  => !empty($in['pushover_events_failed']) ? 1 : 0,
            'pushover_events_blocked' => !empty($in['pushover_events_blocked']) ? 1 : 0,

            'discord_events_sent'     => !empty($in['discord_events_sent']) ? 1 : 0,
            'discord_events_failed'   => !empty($in['discord_events_failed']) ? 1 : 0,
            'discord_events_blocked'  => !empty($in['discord_events_blocked']) ? 1 : 0,

            'slack_events_sent'       => !empty($in['slack_events_sent']) ? 1 : 0,
            'slack_events_failed'     => !empty($in['slack_events_failed']) ? 1 : 0,
            'slack_events_blocked'    => !empty($in['slack_events_blocked']) ? 1 : 0,

            'webhook_events_sent'     => !empty($in['webhook_events_sent']) ? 1 : 0,
            'webhook_events_failed'   => !empty($in['webhook_events_failed']) ? 1 : 0,
            'webhook_events_blocked'  => !empty($in['webhook_events_blocked']) ? 1 : 0,
        ];

        $new = array_merge($cur, $clean);
        update_option($opt_key, $new, false);
        do_action('suremail_notify_options_updated', $new, $cur);

        if ($debug) error_log('[SureMail][child][extra] update_notify OK');

        $information['status']  = 'ok';
        $information['updated'] = array_keys($clean);
        return $information;
    }

    // Unknown / not for us.
    if ($debug) error_log('[SureMail][child][extra] unknown or missing action; ignoring.');
    return $information;
}, 10, 2);


if ( ! function_exists( 'rup_suremail_find_connection_by_id' ) ) {
    function rup_suremail_find_connection_by_id( $id ) {
        $id = (string) $id;

        // Preferred: SureMails settings class
        if ( class_exists( '\SureMails\Inc\Settings' ) ) {
            $settings = \SureMails\Inc\Settings::instance()->get_settings();
            if ( is_array( $settings ) && isset( $settings['connections'][ $id ] ) && is_array( $settings['connections'][ $id ] ) ) {
                return $settings['connections'][ $id ];
            }
        }

        // Fallback 1: whole settings array in an option (common pattern you showed)
        $opt = get_option( 'suremails_settings', [] );
        if ( is_array( $opt ) && isset( $opt['connections'][ $id ] ) && is_array( $opt['connections'][ $id ] ) ) {
            return $opt['connections'][ $id ];
        }

        // Fallback 2: direct connections option (if present in some setups)
        $opt2 = get_option( 'suremails_connections', [] );
        if ( is_array( $opt2 ) && isset( $opt2[ $id ] ) && is_array( $opt2[ $id ] ) ) {
            return $opt2[ $id ];
        }

        return null;
    }
}
