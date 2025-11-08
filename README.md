# Notify for SureMail

**Contributors:** ReallyUsefulPlugins  
**Tags:** email, notifications, pushover, slack, discord, webhook  
**Requires at least:** 6.5  
**Tested up to:** 6.8.2  
**Requires PHP:** 8.0  
**Stable tag:** 0.9.6  
**License:** GPL-2.0+  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html  

---

## Description

**Notify for SureMail** extends [Suremail](https://suremails.com/) email handling by sending **notifications when emails are blocked, fail, or succeed**.  
The plugin integrates with multiple services, including:

- 📲 **Pushover** – instant push notifications to your devices.  
- 💬 **Slack** – team chat alerts when emails fail or are blocked.  
- 🎧 **Discord** – community or team notifications on email events.  
- 🌐 **Generic Webhook** – forward notifications to any custom endpoint.  

This ensures you never miss a critical notification about your WordPress site’s email delivery.

---

## Features

- Sends **real-time alerts** when email delivery fails or is blocked.  
- Supports multiple channels: **Pushover, Slack, Discord, Webhooks**.  
- Lightweight and optimized for performance.  
- Compatible with **WordPress 6.8.2** and above.  
- Easy integration with other plugins via hooks and actions.  
- **FlowMattic** custom action triggers + payload filter
- MainWP Child** endpoint to **ping**, **snapshot**, **send test emails**, and **update options** remotely

---

## Installation

1. Upload the plugin files to the `/wp-content/plugins/notify-for-suremail` directory, or install via the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Configure your notification settings in **Settings → SureMail Notify**.
4. Add your Pushover, Slack, Discord, or Webhook credentials as needed.

---

## Frequently Asked Questions

### ❓ Does this replace my WordPress email sending?
No. Notify for SureMail doesn’t send emails. It only **notifies you when an email fails, is blocked, or is delivered successfully.** You still need to have SureMail installed 

### ❓ Can I send notifications to multiple services at once?
Yes. You can configure multiple integrations (Slack + Pushover + Webhook, etc.), and Notify for SureMail will broadcast notifications to all of them.

### ❓ Does this work with third-party SMTP plugins?
No . It works alongside SureMail — it only **listens for email delivery results** and sends alerts it uses hooks from SureMails and two WP_Mail hooks to work if your SMTP plugin has them it should work `suremails_mail_blocked` , `wp_mail_succeeded`, `wp_mail_failed`

---

## Screenshots

1. **Settings page** – configure Pushover, Slack, Discord, and Webhook.  

   ![Settings Page](https://github.com/stingray82/repo-images/raw/main/notifications-for-suremail/notificaitons-for-suremail-settings-page.png)

   

2. **Example Slack notification** when a WordPress email fails.  

   ![Slack Failed](https://github.com/stingray82/repo-images/raw/main/notifications-for-suremail/suremail-notify-email-failure-slack.png)

   

3. **Example Discord alert** when email delivery is blocked.

   ![Blocked Email](https://github.com/stingray82/repo-images/raw/main/notifications-for-suremail/suremail-notify-email-blocked-discord.png)

   

4. **Example Pushover alert**  when a WordPress email fails

   ![Image](https://github.com/stingray82/repo-images/raw/main/notifications-for-suremail/suremail-notify-email-failure-pushover.png)

   



---

## Developer Hooks

### FlowMattic: Custom Action Triggers

The plugin exposes **three custom actions** you can select as triggers in FlowMattic:

- `suremail_notify_sent` — fired when `wp_mail_succeeded` occurs  
- `suremail_notify_failed` — fired when `wp_mail_failed` occurs  
- `suremail_notify_blocked` — fired when SureMail blocks an email before send

Each action passes a **structured `$payload` array** designed for FlowMattic, including:

```
[
  'event'        => 'sent'|'failed'|'blocked',
  'subject'      => (string),
  'message'      => (string),          // optionally truncated per settings
  'to'           => string[],          // normalized recipients
  'headers'      => array|string,      // optionally included
  'attachments'  => string[],
  'connection'   => [
      'id'    => (string),             // if the connection could be inferred
      'type'  => (string),
      'title' => (string),
  ],
  'site'         => [
      'name' => (string),
      'url'  => (string),
      'now'  => (string),              // local time
      'tz'   => (string),
  ],
]
```

#### FlowMattic Payload Filter

You can tweak/augment the payload before it’s emitted:

```
add_filter('suremail_flowmattic_payload', function ($payload, $context) {
    // $context includes the raw wp_mail() data and event type
    $payload['meta']['source'] = 'SureMail';
    return $payload;
}, 10, 2);
```



## MainWP Communication (Child)

Notify for SureMail includes a **MainWP Child** helper that lets your MainWP Dashboard **query status**, **fetch a snapshot**, **send test emails**, and **update plugin options** on child sites.

### Snapshot Contents 

- Plugin active state and version
- Channel enablement and credentials presence
- Event toggles (sent/failed/blocked per channel)
- Body/header inclusion and truncation settings
- Detected connections and site meta (name, URL, timezone)
- SureMail Email Logs
- SureMail Connection Details
- SureMail Status

---

## License

This plugin is licensed under the **GPL-2.0+ license**.  
See [GPL-2.0+](https://www.gnu.org/licenses/gpl-2.0.html) for details.

---

## Author

Developed and maintained by [ReallyUsefulPlugins.com](https://reallyusefulplugins.com) 🚀
