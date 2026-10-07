# Solaris Discord Link for WHMCS

A WHMCS addon module that lets authenticated clients link or unlink their Discord accounts using OAuth2 (`identify` scope). Includes an administrator view of linked accounts.

## Requirements
- WHMCS with PHP 8.1+ and cURL
- HTTPS WHMCS System URL
- A Discord application

## Install
1. Copy `modules/addons/discordlink/` into your WHMCS installation.
2. Go to **System Settings → Addon Modules**, activate **Discord Account Link**, and configure its Discord Application ID and Client Secret.
3. In the Discord Developer Portal, configure the OAuth2 redirect URI shown in the addon admin page:
   `https://YOUR-WHMCS-DOMAIN/index.php?m=discordlink&action=callback`
4. Grant staff access to the addon through WHMCS addon permissions.
5. Clients can open `https://YOUR-WHMCS-DOMAIN/index.php?m=discordlink` while signed in.

## Behavior
- OAuth state is random, hashed at rest, one-use and expires after ten minutes.
- OAuth is bound to the authenticated WHMCS client.
- One Discord account cannot be linked to multiple WHMCS client IDs.
- Unlink requires a session CSRF token.
- Deactivation intentionally retains link records.
- No Discord OAuth tokens are stored.
- Cerberus bot role synchronization is not included in this initial release.

## Notes
Do not commit Discord application secrets. Set the WHMCS System URL to HTTPS and ensure the redirect URI matches exactly. Test against a staging WHMCS installation before production deployment.
