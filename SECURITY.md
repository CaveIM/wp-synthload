# Security Policy

## Reporting a vulnerability

Please report suspected vulnerabilities privately to [support@mightybox.io](mailto:support@mightybox.io). Do not include exploit details, credentials, access tokens, or affected-site information in a public issue.

Include the plugin version, WordPress and PHP versions, a concise description, reproduction steps, and the potential impact when available. MightyBox will acknowledge the report and coordinate remediation and disclosure with the reporter.

## Supported versions

Security fixes are provided for the latest released major version. Users should upgrade to the newest available release before reporting an issue that may already be resolved.

## Operational security

WP Synthetic Load intentionally generates resource-intensive traffic. Keep its endpoint disabled outside authorized test windows, require a unique `X-SynthLoad-Token`, use HTTPS, and rotate the token if it may have been exposed.
