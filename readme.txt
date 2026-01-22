=== WordPress MCP Ability Suite ===
Contributors: yourname
Tags: mcp, ai, model-context-protocol, api, saas
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Expose WordPress content operations to AI agents via the Model Context Protocol (MCP) with SaaS authentication support.

== Description ==

WordPress MCP Ability Suite provides a complete MCP server implementation for WordPress, enabling AI agents and external SaaS applications to interact with your WordPress site securely.

= Features =

* **25+ MCP Tools** - Create, read, update, and delete posts, manage media, taxonomies, and more
* **4 MCP Resources** - Block schemas, style guides, category templates, writing regulations
* **4 MCP Prompts** - Article generation, format conversion, SEO optimization, regulation learning
* **SaaS Authentication** - API keys, JWT tokens, OAuth 2.0 client credentials
* **MCP Specification Compliant** - Follows MCP 2025-03-26 specification
* **Rate Limiting** - Built-in request throttling
* **Audit Logging** - Track all authentication attempts

= Authentication Methods =

* Bearer Token (JWT)
* API Key with HMAC signature
* Basic Auth (API key + secret)
* OAuth 2.0 Client Credentials Grant

= MCP Endpoints =

After activation, the following endpoints become available:

* MCP Server: `/wp-json/mcp/mcp-adapter-default-server`
* Token Endpoint: `/wp-json/wp-mcp/v1/token`
* API Keys: `/wp-json/wp-mcp/v1/api-keys`
* OAuth Metadata: `/.well-known/oauth-protected-resource`

= Requirements =

* PHP 8.1 or higher
* WordPress 6.0 or higher

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > MCP SaaS to configure authentication
4. Generate API keys for your SaaS application

== Frequently Asked Questions ==

= How do I connect my SaaS application? =

1. Enable SaaS Authentication in Settings > MCP SaaS
2. Generate an API key for your application
3. Use the token endpoint to obtain an access token
4. Make requests to the MCP endpoint with the Bearer token

= Is this secure for production use? =

Yes. The plugin implements:
- Token hashing with SHA-256
- Rate limiting
- HTTPS enforcement (optional)
- Audit logging
- Scope-based permissions

= Does this work with Claude, ChatGPT, or other AI tools? =

Yes, any MCP-compatible client can connect to this server.

== Changelog ==

= 1.0.0 =
* Initial release
* 25 MCP tools for WordPress content operations
* SaaS authentication system with API keys and JWT
* OAuth 2.0 metadata endpoints
* Admin settings page

== Upgrade Notice ==

= 1.0.0 =
Initial release.
