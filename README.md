# WP Synthetic Load

A WordPress plugin that provides a synthetic load endpoint for load testing with Loader.io and similar services.

## Overview

WP Synthetic Load creates a dedicated endpoint that generates configurable database and CPU workload, enabling you to:

- Verify your hosting can handle expected traffic
- Test autoscaling triggers
- Benchmark database performance
- Validate object caching effectiveness
- Identify bottlenecks before they impact real users

The plugin writes **only to its own isolated database table** - your WordPress content (posts, pages, users, settings) is never modified.

## Requirements

- WordPress 6.4+
- PHP 8.1+

## Installation

1. Upload the `wp-synthload` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Configure settings at **Settings → Synthetic Load**

## Endpoints

### Synthetic Load Endpoint

**Default URL:** `https://yoursite.com/synthload/`

Returns `OK` with a 200 status code after executing the configured workload.

**JSON Format:** `https://yoursite.com/synthload/?format=json`

Returns detailed execution data including:
- Request ID and timestamp
- Execution duration vs target
- Number of database reads/writes performed
- Detailed operation log with timing
- Server info (PHP/WP versions)

**Example JSON Response:**
```json
{
  "status": "ok",
  "timestamp": "2024-01-15T10:30:00+00:00",
  "request_id": "550e8400-e29b-41d4-a716-446655440000",
  "execution": {
    "duration_ms": 3042,
    "target_ms": 3000,
    "db_reads": 100,
    "db_writes": 5,
    "cache_hit": false
  },
  "operations": [
    {
      "type": "read",
      "action": "get_option",
      "time_ms": 2,
      "details": { "option": "blogname", "found": true }
    },
    {
      "type": "write",
      "action": "insert",
      "time_ms": 45,
      "details": { "table": "wp_synthload_events", "insert_id": 1234 }
    }
  ],
  "server": {
    "php_version": "8.2.0",
    "wp_version": "6.4.2"
  }
}
```

### Loader.io Verification Endpoint

**URL:** `https://yoursite.com/loaderio-{token}.txt`

Returns the verification token for Loader.io domain verification. Configure your token in the plugin settings.

## Settings Reference

### Loader.io Verification

| Setting | Description |
|---------|-------------|
| **Verification Token** | Your Loader.io verification token (alphanumeric only). Leave empty to disable. |

### Endpoint Configuration

| Setting | Default | Description |
|---------|---------|-------------|
| **Endpoint Slug** | `synthload` | URL path for the load endpoint. Alphanumeric and hyphens only. |
| **Enable Endpoint** | Yes | Toggle the synthetic load endpoint on/off. |
| **Access Token** | (empty) | Optional security token. If set, requests must include `?token=xxx` or `X-SynthLoad-Token` header. |

### Workload Profile

| Profile | Reads | Writes | Duration | Jitter | Use Case |
|---------|-------|--------|----------|--------|----------|
| **General WP** | 100 | 5 | 3000ms | 750ms | Standard WordPress site |
| **Membership** | 200 | 15 | 4000ms | 1000ms | Sites with user sessions, member content |
| **E-commerce** | 150 | 25 | 5000ms | 1000ms | WooCommerce, cart operations |

Select a profile to load its defaults, then customize as needed.

### Workload Parameters

| Setting | Default | Range | Description |
|---------|---------|-------|-------------|
| **Database Reads** | 100 | 0-2000 | Number of read queries per request |
| **Database Writes** | 5 | 0-200 | Number of write operations per request |
| **Target Duration** | 3000ms | 100-15000ms | Target execution time |
| **Duration Jitter** | 750ms | 0-5000ms | Random variation in duration |
| **Randomize Workload** | Yes | - | Add variance to read/write counts |

### Cache Behavior

| Setting | Default | Description |
|---------|---------|-------------|
| **Use cache-friendly operations** | Yes | Use WordPress functions that benefit from object caching |
| **Bypass object cache** | No | Force queries to hit the database directly |

**Cache Behavior Explained:**

| Scenario | use_object_cache | bypass_object_cache | Effect |
|----------|-----------------|---------------------|--------|
| Normal test | ✓ | ✗ | Tests realistic cached performance |
| Stress test | ✗ | ✓ | Tests raw database capacity |
| Cache validation | ✓ | ✗ | Verify caching is working |
| Worst-case test | ✗ | ✓ | Simulate complete cache miss |

When **bypass_object_cache** is enabled:
- Post queries use `cache_results => false`
- Meta and term caches are disabled
- User queries fall back to option reads

### Debug Settings

| Setting | Default | Description |
|---------|---------|-------------|
| **Enable debug logging** | No | Log workload events to PHP error log |

## What the Plugin Reads and Writes

### Read Operations (Non-destructive)

The plugin performs read-only queries against:

| Source | Method | Data Read |
|--------|--------|-----------|
| WordPress Options | `get_option()` | blogname, siteurl, admin_email, etc. |
| Posts Table | `get_posts()` / Direct SQL | Random published posts (ID, title) |
| Users Table | `get_users()` | User IDs only |
| Options Table | Direct SQL | Autoload options list |
| Plugin Events Table | Direct SQL | Random synthetic events |

**No WordPress core data is ever modified.**

### Write Operations (Plugin Table Only)

All writes go to the plugin's dedicated `wp_synthload_events` table:

| Operation | Percentage | Description |
|-----------|------------|-------------|
| INSERT | 60% | Creates new synthetic event rows |
| UPDATE | 30% | Updates payload of existing events |
| DELETE | 10% | Removes old events (only when >80% of max capacity) |

### Database Table Schema

```sql
CREATE TABLE wp_synthload_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  request_id char(36) NOT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  payload longtext,
  rand_key bigint(20) unsigned NOT NULL,
  PRIMARY KEY (id),
  KEY idx_created_at (created_at),
  KEY idx_rand_key (rand_key)
);
```

## Hard Safety Limits

These limits are enforced in code and cannot be exceeded regardless of settings:

| Limit | Value | Purpose |
|-------|-------|---------|
| Max Total Duration | 15,000ms | Prevent request timeouts |
| Max Read Queries | 2,000 | Prevent database overload |
| Max Write Operations | 200 | Limit table growth per request |
| Max Rows to Keep | 100,000 | Cap table size, triggers cleanup |

## Access Control

### Token Authentication

When an access token is configured, requests must authenticate via:

1. **Query Parameter:** `?token=your_secret_token`
2. **HTTP Header:** `X-SynthLoad-Token: your_secret_token`

Unauthenticated requests receive a `403 Forbidden` response.

### HEAD Requests

HEAD requests return `200 OK` immediately without executing workload, useful for uptime monitoring.

## Using with Loader.io

1. **Get your verification token** from Loader.io when adding a new target host
2. **Enter the token** in Settings → Synthetic Load → Loader.io Verification
3. **Verify your domain** in Loader.io (it will check `/loaderio-{token}.txt`)
4. **Create a test** targeting your synthload endpoint:
   - URL: `https://yoursite.com/synthload/`
   - Method: GET
   - Add token header if configured: `X-SynthLoad-Token: your_token`

### Recommended Test Progression

1. **Baseline:** 10 clients, 1 minute - verify endpoint works
2. **Light Load:** 50 clients, 2 minutes - check response consistency
3. **Medium Load:** 200 clients, 5 minutes - monitor for degradation
4. **Stress Test:** 500+ clients, 5 minutes - find breaking point

## Activation & Deactivation

### On Activation

- Creates `wp_synthload_events` table
- Sets default options
- Seeds 500 initial events for consistent reads
- Registers URL rewrite rules

### On Deactivation

- Removes rewrite rules
- **Preserves** options and database table (for reactivation)

### On Uninstall (Delete Plugin)

- Removes all options
- Drops `wp_synthload_events` table
- Cleans up transients

## Troubleshooting

### Endpoint Returns 404

1. Go to Settings → Permalinks and click "Save Changes" to flush rewrite rules
2. Verify the endpoint slug doesn't conflict with existing pages/posts
3. Check that pretty permalinks are enabled (not "Plain")

### Endpoint Returns 403

- Verify your access token matches (query param or header)
- Check for typos in the token

### Slow Response Times

- Reduce read/write counts
- Enable object caching (Redis/Memcached)
- Check database server performance
- Review `?format=json` output for slow operations

### Table Growing Too Large

- Table auto-cleans when >80% of max capacity
- Manually truncate via phpMyAdmin if needed
- Consider lowering write_op_count

## Development

### Running Tests

```bash
cd wp-content/plugins/wp-synthload
composer install
./vendor/bin/phpunit
```

### Test Files

- `tests/test-settings.php` - Settings validation
- `tests/test-db.php` - Database operations
- `tests/test-router.php` - URL routing
- `tests/test-access-control.php` - Token authentication
- `tests/test-workload.php` - Workload execution
- `tests/test-admin.php` - Admin interface
- `tests/test-activation.php` - Activation/deactivation
- `tests/test-integration.php` - End-to-end tests

## License

GPL v2 or later

## Changelog

### 1.0.0
- Initial release
- Synthetic load endpoint with configurable workload
- Loader.io verification support
- Admin settings page
- Access token authentication
- Detailed JSON response format with operation logging
