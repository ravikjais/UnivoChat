# UnivoChat

A self-hosted team chat app built with plain PHP and MySQL/MariaDB. It has direct and group messaging, file sharing, one-to-one audio and video calls, and browser push notifications. It installs as a PWA and runs on XAMPP or standard shared hosting, with no build step.

Current version: **2.6.1**

## Features

- **Messaging:** direct messages and group chats, with typing indicators, read tracking, reactions, and editing, deleting and forwarding of messages
- **Search:** find people and message content from the sidebar
- **Attachments:** images and documents up to 50 MB, and up to 40 files per message. Files are only served to members of the conversation
- **Voice notes and camera capture:** record and send from the browser
- **Calls:** one-to-one audio and video over WebRTC. Media goes directly between browsers, or through a TURN relay if you configure one
- **GIFs, stickers and memes:** a media picker powered by [KLIPY](https://klipy.com) (needs an API key)
- **Push notifications:** Web Push, so notifications arrive while the app is closed
- **Accounts:** new sign-ups wait for approval, and forgotten passwords are reset by email
- **Admin panel:** approve, reject, disable, promote and demote users
- **Default group:** every approved user is added to a shared group, "Univo Team" by default

Levels, streaks and badges are recorded in the background, but their interface is off by default. Set `FEATURE_GAMIFY_UI` in `config/config.php` to turn it on.

## Requirements

- PHP 8.1 or newer, with the `pdo_mysql`, `fileinfo`, `mbstring`, `openssl` and `curl` extensions
- MySQL or MariaDB
- Apache or LiteSpeed, with `.htaccess` support
- HTTPS in production, because browsers only allow push notifications, calls and microphone or camera access on secure pages (`localhost` is exempt)

PHPMailer is already included in `vendor/`, so you don't need to run Composer.

## Local setup with XAMPP

1. Clone the repository into `htdocs`:

   ```bash
   git clone https://github.com/ravikjais/UnivoChat.git
   ```

2. Start Apache and MySQL. Local installs connect to a database called `univochat` as `root` with no password.
3. Open `http://localhost/UnivoChat/setup/install.php` and run the installer. It creates the database, tables, upload folders, superadmin account and default group.
4. Sign in as `admin@univochat.local`. The default password is `$adminPass` in `setup/install.php`. Change it straight after your first sign-in.

Any host ending in `.local` or `.test`, and any `192.168.*` address, also counts as local. Everything else runs in production mode.

## Production setup

1. Upload the code and make `uploads/` writable. Make `config/` writable for the first run too, so the app can create its key file, `config/.appkey`.
2. Create `config/config.local.php` with your settings (see [Configuration](#configuration)).
3. Set up the database in one of two ways:
   - **Installer:** open `/setup/install.php` in your browser.
   - **SQL import:** import `sql/univochat_schema.sql` into a new, empty database. It contains the current structure, the superadmin and the default group, but no other data.
4. Sign in and change the superadmin password.
5. **Delete the `setup/` folder.**

`sql/univochat_schema.sql` has no `DROP` statements, so importing it over an existing database fails rather than overwriting data.

When moving an existing install to a new server, copy `config/.appkey` as well. Attachment links are signed with it.

## Configuration

`config/config.php` holds the defaults and contains no secrets. Anything defined in `config/config.local.php` overrides those defaults. That file is git-ignored, so never commit it.

```php
<?php
// config/config.local.php

if (APP_ENV !== 'local') {
    define('DB_NAME', 'your_database');
    define('DB_USER', 'your_user');
    define('DB_PASS', 'your_password');
}

// Password-reset email
define('SMTP_HOST', 'smtp.example.com');
define('SMTP_USER', 'mail@example.com');
define('SMTP_PASS', 'your_smtp_password');
define('SMTP_FROM', 'mail@example.com');   // should match SMTP_USER

// GIFs, stickers and memes
define('KLIPY_API_KEY', 'your_klipy_key');

// Calls between restrictive networks need a TURN relay
// define('CALL_TURN_URLS', ['turn:turn.example.com:3478']);
// define('CALL_TURN_SECRET', 'your_coturn_secret');
```

Only the database settings are required. Without SMTP the app reports that password-reset email isn't configured, and without a KLIPY key the media picker is off.

Calls use public STUN servers by default. These work on many networks, but without TURN some calls connect and then carry no sound or picture.

## Project structure

| Path | Contents |
| --- | --- |
| `admin/` | Admin panel and its actions |
| `ajax/` | JSON endpoints used by the chat interface |
| `assets/` | CSS, JavaScript and icons |
| `config/` | App configuration and database connection |
| `includes/` | Shared code for auth, calls, push, mail and media |
| `setup/` | Installer (delete after installing) |
| `sql/` | Clean database file for new installs |
| `uploads/` | User avatars and attachments (not in Git) |
| `vendor/` | PHPMailer |
| `sw.js`, `manifest.json` | Service worker and PWA manifest |

## License

[MIT](LICENSE)
