=== Notify for SureMail ===
Contributors: reallyusefulplugins
Donate link: https://reallyusefulplugins.com/donate
Tags: SureMail, Discord, Pushover, Slack, Webhook
Requires at least: 6.5
Tested up to: 7.0
Stable tag: 1.0.2
Requires PHP: 8.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends Pushover, Discord, Generic Webhook and Slack notifications when emails are blocked, fail, or succeed.
== Description ==

Sends Pushover, Discord, Generic Webhook and Slack notifications when emails are blocked, fail, or succeed.

== Installation ==

1. Upload the `notify-for-suremail` folder to the `/wp-content/plugins/` directory.
2. Go to a Guttenberg page you will now have a new block.
3. use the configurator to modify the use bubbles embed

== Frequently Asked Questions ==

== Changelog ==
= 1.0.2 31 May 2026 =

New: Added SUREMAIL_NOTIFY_DEBUG_ALL constant for detailed notification debugging and delivery tracing.
New: Added channel routing diagnostics to log event routing decisions and notification delivery attempts.
New: Added HTTP response logging for Pushover, Discord, Slack and generic webhook notifications.
Improve: Reduced notification payload size to improve compatibility with webhook providers and avoid message-length limits
Improve: Slack and Discord notifications have been simplified to use a concise notification format containing:Site name, Event type, Recipient, Sender, Subject, Error summary (where available)
Improve:Test notifications now use the same event-routing logic as live notifications, ensuring tests accurately reflect production behaviour.
Fixed: Fixed an issue where test notifications could succeed while live notifications were blocked by event-routing settings.
Fixed: Fixed Discord webhook failures caused by payloads exceeding Discord's message length limits.
Fixed: Fixed Slack webhook failures caused by oversized notification payloads.

= 1.0.1 23 March 2026 =
Update: Updater to 2.0-Alpha
Update: Compatibility

= 1.0 Feb 2026 = 
New: Hidden Input Fields
New: WPConfig Setup
Improve: All Forms are now individual Forms

= 0.9.8 09 November 2025 =
New: Update Deploy Script

= 0.9.7 22 August 2025 =
New: First Updater Test (Update)
New: Notify for Suremail

= 0.9.6 22 August 2025 =
New: Initial Release
New: First Updater Test