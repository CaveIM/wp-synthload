# WP Synthetic Load

WP Synthetic Load is a WordPress plugin that creates an authenticated endpoint for controlled load testing with Loader.io and similar services. It performs configurable database reads, isolated write cycles, and CPU work so you can measure a WordPress environment under repeatable load.

The plugin does not launch tests or collect analytics. Your external load-testing service sends requests and measures the results.

## Safety first

- The workload endpoint is disabled by default.
- Every workload request requires an `X-SynthLoad-Token` header.
- Authentication through URL query parameters is not supported because URLs are commonly retained in logs, browser history, analytics, and proxies.
- Writes are limited to the plugin-owned `{$wpdb->prefix}synthload_events` table.
- Hard caps apply even when a request supplies workload overrides.
- JSON responses contain workload metrics only; they do not expose WordPress or PHP versions, database table names, option names, or WordPress object IDs.

Only load-test systems you own or are explicitly authorized to test. Prefer a staging environment. On production systems, enable the endpoint only for the test window and disable it afterward.

## Requirements

- WordPress 6.4 or newer
- PHP 8.1 or newer
- HTTPS for authenticated requests

## Installation

1. Upload the `wp-synthload` directory to `wp-content/plugins/`.
2. Activate **WP Synthetic Load** in WordPress.
3. Open **Settings → Synthetic Load → Settings**.
4. Configure a strong, unique access token. A randomly generated value of at least 32 characters is recommended.
5. Enable the endpoint and save.
6. Configure workload parameters under the **Workload** tab.

The default endpoint URL is:

```text
https://your-site.example/synthload/
```

## Authentication

Send the configured token in the `X-SynthLoad-Token` request header:

```bash
export SYNTHLOAD_TOKEN='replace-with-your-token'
curl \
  --header "X-SynthLoad-Token: ${SYNTHLOAD_TOKEN}" \
  https://your-site.example/synthload/
```

A successful plain-text request returns:

```text
OK
```

Requests with a missing or incorrect token return `403 Forbidden`. A valid token in `?token=...` is intentionally rejected.

## Running a Loader.io test

### Verify the target host

1. Add your hostname in Loader.io.
2. Copy its verification token into **Settings → Synthetic Load → Settings → Loader.io Verification**.
3. Save the settings. The plugin creates Loader.io's verification file in the WordPress web root.
4. Complete host verification in Loader.io.

The verification file is public by design, but it cannot run a workload.

### Configure the test request

1. In Loader.io, create or edit a test for `https://your-site.example/synthload/`.
2. Open the request's headers/options section.
3. Add this HTTP header:

   ```text
   X-SynthLoad-Token: replace-with-your-token
   ```

4. Choose the client count, test type, duration, timeout, and error threshold.
5. Start with a small test and increase load gradually while monitoring the application, database, cache, and hosting controls.

Loader.io supports custom HTTP headers in both its web interface and API. When creating tests through its API, place the plugin token in the request configuration's `headers` object:

```json
{
  "test_type": "maintain-load",
  "urls": [
    {
      "url": "https://your-site.example/synthload/",
      "request_type": "GET",
      "headers": {
        "X-SynthLoad-Token": "replace-with-your-token"
      }
    }
  ],
  "duration": 60,
  "initial": 1,
  "total": 10,
  "name": "WordPress synthetic load"
}
```

The Loader.io API itself uses its own `loaderio-auth` credential. That credential is separate from the `X-SynthLoad-Token` sent to WordPress.

## JSON results

Add `format=json` when you need machine-readable workload metrics. Authentication remains in the header:

```bash
export SYNTHLOAD_TOKEN='replace-with-your-token'
curl \
  --header "X-SynthLoad-Token: ${SYNTHLOAD_TOKEN}" \
  'https://your-site.example/synthload/?format=json'
```

Example response:

```json
{
  "status": "ok",
  "timestamp": "2026-01-15T10:30:00+00:00",
  "request_id": "550e8400-e29b-41d4-a716-446655440000",
  "execution": {
    "duration_ms": 142.7,
    "db_reads": 100,
    "db_writes": 15,
    "cpu_iterations": 100000,
    "cache_hit": false
  }
}
```

`db_writes` reports individual database operations. Each configured write cycle performs an insert, update, and delete, so five cycles report 15 operations and leave no new workload row behind.

## Per-request workload overrides

An authenticated request may override saved workload values with query parameters. The access token must still be sent in the header.

| Parameter | Meaning | Hard maximum |
|---|---|---:|
| `read_query_count` | Database read queries | 2,000 |
| `write_op_count` | Complete insert/update/delete cycles | 200 |
| `cpu_iterations` | Thousands of SHA-256 operations | 10,000 (10 million operations) |
| `bypass_object_cache` | Accepts `1`, `true`, or `yes` | — |

Example:

```bash
export SYNTHLOAD_TOKEN='replace-with-your-token'
curl \
  --header "X-SynthLoad-Token: ${SYNTHLOAD_TOKEN}" \
  'https://your-site.example/synthload/?read_query_count=250&write_op_count=10&cpu_iterations=500&format=json'
```

These overrides are useful for separate Loader.io scenarios without repeatedly changing WordPress settings. Treat the access token as permission to execute workloads up to the hard caps.

## Workload behavior

### Reads

The plugin performs read-only operations against WordPress options, published posts, user IDs, and its own events table. Read values and identifiers are not returned to the requester.

### Writes

Every write cycle performs `INSERT → UPDATE → DELETE` against the plugin's own table. The plugin never writes to WordPress core content, user, or settings tables as part of the workload.

### CPU work

CPU work performs a fixed number of SHA-256 operations. The setting is expressed in thousands: `100` means 100,000 hash operations.

### Cache bypass

Cache bypass disables object-query caching where possible and sends headers intended to prevent page, proxy, and CDN caching for the workload response.

## Operational recommendations

- Use a unique token for each site and rotate it if it is exposed.
- Never place the token in a URL.
- Restrict the endpoint at the firewall or WAF when practical.
- Account for Loader.io source-address behavior before allow-listing traffic.
- Begin with a low connection count and short duration.
- Watch database connections, CPU, memory, PHP workers, cache health, and error rates.
- Stop the test if the site affects other tenants or services.
- Disable the endpoint after testing.

## Activation and removal

Activation creates the plugin table, seeds synthetic rows, stores safe defaults, and registers rewrite rules. Deactivation disables the rewrite rules while preserving settings and the table. Deleting the plugin removes its settings, table, transients, and Loader.io verification file.

## Development

Install development dependencies and run the WordPress PHPUnit suite:

```bash
composer install
composer test
```

The test bootstrap expects `WP_TESTS_DIR` to point to a configured WordPress test library. If it is unset, it looks in the system temporary directory under `wordpress-tests-lib`.

Validate PHP syntax without a WordPress test environment:

```bash
find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;
```

## Version 2.0 migration

Version 2.0 makes the workload endpoint secure by default:

- New installations start with the endpoint disabled.
- Existing enabled endpoints without a token return `403` until a token is configured.
- Existing access tokens shorter than 16 characters must be replaced.
- Query-string authentication has been removed. Update all load-test configurations to send `X-SynthLoad-Token`.
- JSON results no longer include server versions or detailed database-operation metadata.
- Hard caps are 2,000 reads, 200 write cycles, and 10 million CPU iterations per request.

## License

Copyright MightyBox.

WP Synthetic Load is licensed under the GNU General Public License v2.0 or later. See `LICENSE`.

## Support

Contact [support@mightybox.io](mailto:support@mightybox.io).

Please report security vulnerabilities privately as described in `SECURITY.md` rather than opening a public issue.
