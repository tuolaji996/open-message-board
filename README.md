# Open Message Board

A lightweight open source message board built with PHP and MySQL. It supports public posts, image uploads, comments, email verification hooks, basic admin moderation, optional bot protection, visitor metadata review, and a configurable Yahoo Finance market widget.

## Features

- Public message posting with optional title, author name, email, hashtags, and images.
- Comment threads for published posts.
- Admin moderation for reviewing and deleting content.
- MySQL schema with posts, images, comments, hashtags, and moderation logs.
- Configurable site name, base URL, timezone, upload limits, and allowed image types.
- Optional Cloudflare Turnstile verification.
- Visitor metadata capture for moderation: IP address, user agent, browser language, and browser timezone.
- Configurable Yahoo Finance ticker card with a cached one-month sparkline.
- Private application configuration kept outside the public web root.

## Requirements

- PHP 8.1 or newer with PDO MySQL enabled.
- MySQL 5.7 or newer, or a compatible MariaDB version.
- A web server such as Nginx, Apache, Caddy, or a PHP-capable hosting panel.
- Write access for the configured upload directory.

## Installation

1. Upload or clone the project onto your server.

2. Point the web root or document root to the `web/` directory.

   The `private/` directory must not be publicly accessible. It contains configuration and database setup files.

3. Create a MySQL database and user for the application.

4. Import the database schema:

   ```bash
   mysql -u message_board_user -p message_board < private/schema.sql
   ```

5. Copy the sample configuration:

   ```bash
   cp private/config.sample.php private/config.php
   ```

6. Edit `private/config.php` and set:

   - `site_name`
   - `base_url`
   - `timezone`
   - database DSN, username, and password
   - admin username and password hash
   - mail sender settings
   - market ticker and cache duration
   - upload directory and image limits

7. Generate an admin password hash:

   ```bash
   php -r "echo password_hash('change-this-password', PASSWORD_DEFAULT) . PHP_EOL;"
   ```

   Put the generated value in `private/config.php` as `admin.password_hash`.

8. Create the upload directory if it does not already exist:

   ```bash
   mkdir uploads
   chmod 750 uploads
   ```

   The PHP process must be able to write to this directory. If your host uses a different deployment layout, set `uploads_dir` in `private/config.php` to an absolute path outside the public web root.

9. If you want to update Turnstile or the market ticker from the admin panel, make sure the PHP process can write small override files in `private/`.

## Optional Cloudflare Turnstile

To enable Turnstile:

1. Create a Turnstile widget in your Cloudflare dashboard.
2. Add the site key and secret key to `private/config.php`.
3. Set `turnstile.enabled` to `true`.

Leave Turnstile disabled for local development unless you have valid test keys.

## Optional Market Widget

The left rail can display a Yahoo Finance quote card. Configure it in `private/config.php`:

```php
'market' => [
    'ticker' => 'AAPL',
    'cache_seconds' => 300,
],
```

Admins can also change the ticker from the admin panel. Cached quote files are written under `private/market-cache-*.json` and should not be committed.

## Upgrading

If you installed an earlier version before visitor metadata was added, run:

```bash
mysql -u message_board_user -p message_board < private/migrations/2026-06-22-visitor-metadata.sql
```

## Security and Privacy Notes

- Keep `private/config.php` out of version control.
- Keep the web root pointed at `web/`; do not expose the repository root.
- Store uploads outside the public web root when possible, and serve them through application routes.
- Use a strong admin password and replace any default sample values before deployment.
- Use HTTPS in production.
- Review retention needs for IP addresses, user agents, browser language, browser timezone, email addresses, and moderation logs.
- Back up the database and uploaded files before upgrades.
- Limit database privileges to only what the application needs.

## Development

For local testing, use PHP's built-in server from the project root:

```bash
php -S 127.0.0.1:8080 -t web
```

Then open `http://127.0.0.1:8080`.

## License

MIT License. See `LICENSE` for details.
