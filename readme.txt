=== WordPress MCP Ability Suite ===
Contributors: als141
Tags: mcp, ai, model-context-protocol, api, saas
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Expose WordPress content operations to AI agents via the Model Context Protocol (MCP) with SaaS authentication support.

== Description ==

WordPress MCP Ability Suite provides a complete MCP server implementation for WordPress, enabling AI agents and external SaaS applications to interact with your WordPress site securely.

This plugin registers WordPress abilities (tools, resources, and prompts) using the WordPress Abilities API and exposes them through the MCP Adapter, allowing any MCP-compatible client to discover and invoke WordPress operations.

= Key Features =

* **25+ MCP Tools** - Create, read, update, and delete posts, manage media, taxonomies, and more
* **4 MCP Resources** - Block schemas, style guides, category templates, writing regulations
* **4 MCP Prompts** - Article generation, format conversion, SEO optimization, regulation learning
* **One-Click SaaS Connection** - Connect external SaaS applications with a single button click
* **SaaS Authentication** - API keys, Bearer tokens, Basic Auth
* **MCP Specification Compliant** - Follows MCP 2025-06-18 specification
* **Audit Logging** - Track all authentication attempts

= How It Works =

1. Install and activate the plugin
2. The plugin registers WordPress abilities and starts an MCP server
3. External AI agents or SaaS applications connect via the MCP endpoint
4. Authenticated clients can create posts, manage media, check SEO, and more

= Authentication Methods =

* **Bearer Token** (recommended) - Permanent access tokens for SaaS connections
* **Basic Auth** - API key + secret pair
* **One-Click Registration** - Secure registration code exchange for SaaS integration

= MCP Endpoints =

After activation, the following endpoints become available:

* MCP Server: `/wp-json/mcp/mcp-adapter-default-server`
* SaaS Registration: `/wp-json/wp-mcp/v1/register`
* Connection Callback: `/wp-json/wp-mcp/v1/connection-callback`

= Requirements =

* PHP 8.1 or higher
* WordPress 6.0 or higher (6.9+ recommended for built-in Abilities API)

= Dependencies =

This plugin bundles the following GPL-compatible packages:

* [WordPress Abilities API](https://github.com/WordPress/abilities-api) - GPL-2.0-or-later
* [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) - GPL-2.0-or-later
* [Jetpack Autoloader](https://github.com/Automattic/jetpack-autoloader) - GPL-2.0-or-later

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > MCP Connection to view the MCP endpoint and connect your SaaS application

= Connecting a SaaS Application =

1. Navigate to Settings > MCP Connection
2. Enter your SaaS application URL
3. Click "SaaS と連携する" (Connect to SaaS)
4. The SaaS application will automatically receive credentials
5. Connection status will display on the settings page

== Frequently Asked Questions ==

= How do I connect my SaaS application? =

Go to Settings > MCP Connection, enter your SaaS application URL, and click the connect button. The plugin generates a one-time registration code and redirects to your SaaS application, which exchanges the code for permanent credentials.

= Is this secure for production use? =

Yes. The plugin implements:

* Token hashing with SHA-256
* One-time registration codes (10-minute expiry)
* HTTPS enforcement (when available)
* Audit logging of authentication attempts
* Scope-based permissions (read, write, admin)

= Does this work with Claude, ChatGPT, or other AI tools? =

Yes, any MCP-compatible client can connect to this server, including Claude, ChatGPT, Cursor, and other AI assistants that support the Model Context Protocol.

= What happens to my data? =

This plugin does not send any data to external services by itself. When you connect a SaaS application, only the SaaS application you explicitly authorize can access your WordPress content through the MCP endpoint. See the Privacy section below for details.

= Does the access token expire? =

No. Tokens generated through the one-click SaaS connection flow are permanent and do not expire. You can revoke access at any time by disconnecting from the Settings page.

= Can I connect multiple SaaS applications? =

The plugin currently supports one active SaaS connection at a time. Disconnecting and reconnecting with a different SaaS application is supported.

= What WordPress versions are supported? =

WordPress 6.0 and higher. WordPress 6.9+ is recommended because the Abilities API is included in core. For earlier versions, the plugin bundles the Abilities API as a Composer package.

== Screenshots ==

1. MCP Connection settings page - Connect your SaaS application with one click
2. Connection status - View active SaaS connection details
3. MCP server information - Endpoint URLs and site details

== Changelog ==

= 1.0.2 =
* Fix: Move `declare(strict_types=1)` to first statement in all saas-auth PHP files (fixes fatal error on PHP 8.1+)

= 1.0.1 =
* Security: API secret now stored as hashed value instead of plaintext
* Security: Token introspection endpoint now requires authentication
* Security: Debug endpoint /auth/test only available when WP_DEBUG is enabled
* Security: Protected meta keys blocked in create-draft-post meta input
* Security: admin_email only returned for users with manage_options capability
* Fix: get-block-patterns returns correct schema when registry unavailable
* Fix: create-term now returns correct slug from database
* Fix: get-categories and get-tags include WP_Error checks
* Fix: update-post-meta correctly reports success when value unchanged
* Fix: get-tags now includes empty tags (hide_empty=false)
* Fix: OAuth metadata only advertises implemented features
* Fix: disconnect now also removes API keys for the connected user
* Fix: SEO check no longer reports missing H1 (WordPress title serves as H1)
* Fix: CJK keyword density uses character-based calculation
* Fix: Prompts return WP_Error for invalid post_id/category_id
* Fix: wp_admin_notice fallback for WordPress versions before 6.4
* Fix: Basic Auth now supports hashed API secrets (backwards compatible)

= 1.0.0 =
* Initial release
* 25+ MCP tools for WordPress content operations (posts, media, taxonomies, SEO)
* 4 MCP resources (block schemas, style guides, category templates, writing regulations)
* 4 MCP prompts (article generation, format conversion, SEO optimization, regulation learning)
* One-click SaaS connection with registration code exchange
* SaaS authentication system with permanent Bearer tokens
* API key management with SHA-256 hashing
* Admin settings page with Japanese UI
* Audit logging for authentication attempts
* MCP 2025-06-18 specification compliance

== Upgrade Notice ==

= 1.0.1 =
Security and bug fix release. API secrets are now hashed, debug endpoints are restricted, and multiple tool output fixes. Existing API keys remain compatible.

= 1.0.0 =
Initial release.

== Privacy ==

= External Services =

This plugin connects to external services **only when explicitly configured by the site administrator**:

* **SaaS Connection**: When you click "Connect to SaaS" in Settings > MCP Connection, the plugin redirects to the SaaS URL you specified and exchanges credentials. No data is sent without your action.
* **MCP Communication**: After connection, the authorized SaaS application can make requests to your MCP endpoint to read and write WordPress content. All requests require valid authentication.

= Data Collected =

* The plugin stores API keys, access tokens, and connection metadata in the WordPress database.
* Authentication tokens are stored as SHA-256 hashes (the original token is never stored).
* Audit logs record authentication attempts (timestamp, IP address, success/failure).

= Data Shared =

* Site URL, site name, and MCP endpoint URL are shared with the SaaS application during the connection process.
* No data is shared with any third party unless you explicitly connect a SaaS application.

= User Consent =

* SaaS connection requires explicit administrator action (clicking the connect button).
* No automatic data collection or external communication occurs without user action.
