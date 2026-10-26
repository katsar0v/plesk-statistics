# Changelog

## 1.0.0 – 2026-10-26

First release: a **Live Traffic** page for Plesk administrators that shows the HTTP requests to all hosted websites in near real time.

- **Rolling windows** of 1, 2 or 5 minutes, refreshed every 3 seconds. Each window shows requests per second, a breakdown by website and status, and the newest 500 requests.
- **Every web server's logs** are read: nginx and Apache, HTTP and HTTPS. A static file that both servers log (X-Accel-Redirect in proxy mode) is counted once, with the status nginx sent to the client.
- **Incremental reading** with per-poll byte and time limits. A binary search by timestamp finds the start in large logs. Truncation, replacement and rewriting of a log are detected.
- **Redaction** of credentials, tokens and e-mail addresses in URLs before they are stored.
- **Administrators only.** Runs as the panel user, changes no file permissions and runs nothing as root.
