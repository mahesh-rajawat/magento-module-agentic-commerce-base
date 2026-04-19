# MSR_AgenticUcp — Release Notes

---

## 1.0.0 — Initial release

**Release date:** April 2026
**Compatibility:** Magento 2.4.4+, PHP 8.1+

This is the first public release of MSR_AgenticUcp, the Universal Commerce
Protocol base module for Magento 2. It provides everything a Magento store
needs to be discoverable, authenticatable, and policy-controlled by any
UCP-compliant AI agent — without custom integration code per agent provider.

---

### What is included

#### Discovery

- `GET /.well-known/ucp.json` endpoint serves a structured manifest
  describing the store's capabilities, registered agents, and authentication
  endpoint
- Custom `Controller/Router.php` intercepts the well-known path with
  `sortOrder="22"` — compatible with all standard Magento routing
- Manifest is built at runtime from the admin panel registry —
  no file editing needed to register or update agents

#### Authentication

- `POST /rest/V1/ucp/auth` accepts a Decentralized Identifier (DID) and
  a signed JWT from the agent
- Supports `did:web`, `did:key`, and `did:ethr` DID methods (configurable
  in admin panel)
- `Model/Did/Resolver.php` fetches the agent's DID document over HTTP and
  extracts the public key for signature verification
- `Model/Token/Generator.php` issues scoped HS256 Bearer tokens encoding
  only the capabilities that were both requested and granted
- `Model/Token/Validator.php` validates the Bearer token on every subsequent
  request — checks signature, expiry, and issuer

#### Policy middleware

Five sequential checks enforced by `Plugin/Webapi/AgentPolicyGuard.php`
as a before-plugin on `Magento\Webapi\Controller\Rest::dispatch()`:

1. Token validation — verifies HS256 signature, expiry, issuer → 401
2. Capability check — maps request path to required capability, checks
   token's capabilities claim → 403
3. Rate limiter — sliding 60-second window via Magento cache backend
   (Redis recommended) → 429
4. Order value guard — enforces `max_order_value` on order routes → 422
5. Human confirmation gate — requires `X-UCP-Human-Confirmation` header
   on mutating requests when enabled → 400

High-risk capabilities (`order.place`, `payment.initiate`,
`negotiation.price`) always require human confirmation regardless of the
default policy setting.

#### Capability profiles

- `etc/ucp.xml` defines three built-in capability profiles:
  `profile-readonly`, `profile-shopping`, `profile-full-access`
- XSD uses an open pattern (`[a-z][a-z0-9]*\.[a-z][a-z0-9]*`) instead of
  a closed enum — child modules can define new capability codes without
  modifying the base module
- `Model/Config/Source/Capabilities.php` accepts an `additionalCapabilities`
  constructor argument — child modules inject new capabilities via `di.xml`
  with zero base module changes
- Capability labels and risk levels are shown in the admin panel in plain
  English — store owners never see raw capability codes

#### Admin panel configuration

Located at `Stores → Configuration → MSR → Agentic UCP`.

- **Agent registry** — dynamic add/remove grid. Each row: agent name, DID,
  trust level, capability profile dropdown, active toggle, per-agent
  overrides for max order value, rate limit, and allowed payment methods
- **Capability profiles reference** — read-only table showing each profile
  and what it allows, grouped by risk level
- **Default policies** — global defaults for all agents: human confirmation,
  rate limit, max order value, audit log, token TTL
- **Security** — allowed DID methods multiselect, DID resolution timeout,
  token secret status indicator (shows configured/not configured without
  exposing the value)
- **Recent audit log** — last 20 agent requests shown inline, with
  human-readable capability labels and colour-coded outcomes

Agent identity, permissions, and policies are stored in `core_config_data`
(database). No deployment or file editing is required to onboard a new agent
in production.

#### Three-layer configuration priority

1. Per-agent admin panel config — highest priority
2. Default admin panel config
3. `etc/ucp.xml` capability profiles — lowest priority (code/deploy)

`AgentConfigProvider.php` resolves the merged config at runtime.

#### Audit trail

- `ucp_audit_log` database table records every agent request
- Columns: `agent_did`, `request_path`, `capability`, `capability_label`
  (human-readable), `outcome` (ALLOWED / DENIED / RATE_LIMITED),
  `ip_address`, `created_at`
- Indexes on `agent_did` and `created_at` for efficient reporting
- Logger catches its own exceptions — a database failure never blocks
  the actual request

#### Extensibility

- New capability codes: inject via `di.xml` into
  `MSR\AgenticUcp\Model\Config\Source\Capabilities`
- New capability profiles: add `ucp.xml` to any child module
- Custom policy checks: plugin on `AgentPolicyGuard`
- Auth event hooks: `ucp_agent_authenticated`,
  `ucp_agent_request_allowed`, `ucp_agent_auth_failed`
- Replace auth entirely: preference on `AgentAuthInterface`

---

### Built-in capability codes

| Code | Label | Risk |
|---|---|---|
| `catalog.browse` | Browse catalog | Low |
| `catalog.search` | Search catalog | Low |
| `inventory.query` | Check inventory | Low |
| `cart.manage` | Manage cart | Medium |
| `order.track` | Track orders | Medium |
| `customer.read` | Read customer data | Medium |
| `order.place` | Place orders | High |
| `payment.initiate` | Initiate payments | High |
| `negotiation.price` | Negotiate pricing | High |

---

### Requirements

| Requirement | Version |
|---|---|
| Magento Open Source / Adobe Commerce | 2.4.4 or higher |
| PHP | 8.1 or 8.2 |
| OpenSSL PHP extension | enabled |
| Redis | 6.0+ recommended for rate limiting |

---

### Installation

```bash
composer require msr/module-agentic-ucp

php bin/magento module:enable MSR_AgenticUcp
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

Add token secret to `app/etc/env.php`:

```php
'ucp' => [
    'token_secret' => 'your-generated-secret-min-32-chars',
],
```

Then register your first agent at
`Stores → Configuration → MSR → Agentic UCP → Agent registry`.

---

### Companion module

Install `MSR_AgenticUcpCheckout` alongside this module to add catalog,
cart, checkout, and order REST endpoints.

---

### Development status

The v1.0.0 codebase ships with local development patches intentionally
included to make local testing possible without a live domain or
cryptographic key infrastructure. These patches are clearly marked
with `// DEV MODE` comments and must be removed before production use.

A `v1.0.0-stable` tag will be created once the patches are reverted
and ES256 token signing is implemented.

### Known limitations in this release

- `did:key` and `did:ethr` DID methods are listed in admin but only
  `did:web` resolution is fully implemented in `Resolver.php`. Support
  for other methods is planned for a future release.
- Token signing uses HS256 (symmetric). ES256 (asymmetric) token signing
  is on the roadmap — required for production deployments where the
  Magento server and token validation are on separate infrastructure.
- Human confirmation one-time tokens are currently validated as
  non-empty strings. A cryptographic one-time token system with
  expiry is planned.
- Rate limiting uses Magento's default cache backend. A dedicated
  Redis rate limit store with sub-second precision is planned.

---

### Security notes

- The `ucp/token_secret` in `env.php` must be at least 32 random bytes.
  Generate with: `php -r "echo bin2hex(random_bytes(32));"`
- Never commit `env.php` to version control.
- DID resolution makes outbound HTTP requests during authentication.
  Set a conservative `did_resolution_timeout` in admin (3–5 seconds
  recommended) to prevent slow auth on unreachable DID documents.

---

### Module details

| Detail | Value |
|---|---|
| Module name | `MSR_AgenticUcp` |
| Composer package | `msr/module-agentic-ucp` |
| Version | `1.0.0` |
| Licence | MIT |
| Namespace | `MSR\AgenticUcp` |
| Setup version | `1.0.0` |
| Depends on | `Magento_Webapi`, `Magento_Config`, `Magento_Store` |

---

## Roadmap

These are planned for future releases in priority order:

- **1.1.0** — ES256 JWT token signing for production deployments
- **1.1.0** — Cryptographic one-time tokens for human confirmation gate
- **1.2.0** — `did:key` and `did:ethr` full resolver implementation
- **1.2.0** — Webhook callbacks for order status push to agent
- **1.3.0** — Multi-store scope — per-website agent registry
- **1.3.0** — GraphQL endpoint alongside REST
- **2.0.0** — Redis-native rate limiter with sub-second precision
- **2.0.0** — Agent analytics dashboard in admin panel
