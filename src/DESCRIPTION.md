Live Traffic Monitor shows the HTTP requests reaching every website on this server in near real time.

- Rolling windows of the last 1, 2 or 5 minutes, refreshed every 3 seconds.
- Requests per second, a breakdown by website and by status, and the newest requests with time, website, method, URL, status, IP address and size.
- Reads the existing nginx and Apache access logs incrementally. It doesn't count a request twice when both web servers log it, and it keeps working after log rotation.
- Shows which Docker container answered the requests to websites behind a Docker proxy rule, with each container's state and health.
- Hides tokens, passwords, e-mail addresses and other secrets in URLs.
- Only server administrators can open it.

The counts are HTTP requests, not unique visitors or page views. Client-side navigation in single-page apps and responses served from a CDN cache never reach the server, so they don't appear.
