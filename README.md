# MSR_AgenticUcp — UCP Agentic Commerce Base Module

Adds Universal Commerce Protocol (UCP) support to Magento 2, enabling AI agents
to discover, authenticate, and transact on your storefront in a structured,
policy-controlled way.

This is the **base module**. It handles identity, authentication, policy
enforcement, and admin configuration. The companion module
`MSR_AgenticUcpCheckout` provides the catalog, cart, checkout, and order
REST endpoints.

---

## What this module provides

| Feature | Where |
|---|---|
| Agent capability manifest | `GET /.well-known/ucp.json` |
| Agent authentication (DID + JWT) | `POST /rest/V1/ucp/auth` |
| Policy middleware (all `/V1/ucp/*` routes) | `Plugin/Webapi/AgentPolicyGuard.php` |
| Capability profiles (what agents can do) | `etc/ucp.xml` |
| Admin configuration UI | `Stores → Configuration → MSR → Agentic UCP` |
| Extensible capability registry | `Model/Config/Source/Capabilities.php` |
| Audit trail | `ucp_audit_log` database table |

---

## Architecture overview

```
Admin panel (Stores → Config → MSR → Agentic UCP)
│
├── Agent registry — WHO the agents are
│     name, DID, trust level, profile, active, per-agent overrides
│     stored in: core_config_data (database)
│     no file editing ever needed
│
├── Default policies — global limits
│     rate limit, max order value, human confirmation, TTL
│     stored in: core_config_data (database)
│
└── ucp.xml — capability profiles only
      what each profile CAN do
      stored in: module files (version controlled)
      changed only when: a new feature module is installed
```

### Design principles

**Single source of truth** — agent identity and policies live in the database
(admin panel). Capability profiles live in code (ucp.xml). Never both.

**Open/Closed** — child modules add capabilities by injecting into
Capabilities source model via di.xml. Base module files are never modified.

**No hardcoded agents** — ucp.xml contains only profile-* entries.
Real agents (DIDs) are registered in the admin panel at runtime.

---

## Requirements

| Requirement | Version |
|---|---|
| Magento | 2.4.4+ |
| PHP | 8.1 or 8.2 |
| OpenSSL PHP extension | enabled |
| Redis (recommended) | 6.0+ for rate limiting |

---

## Installation

### Step 1 — Copy the module

```bash
unzip MSR_AgenticUcp.zip -d /var/www/html/app/code/
```

### Step 2 — Enable and compile

```bash
cd /var/www/html
php bin/magento module:enable MSR_AgenticUcp
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

### Step 3 — Add token secret to env.php

```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

Open `app/etc/env.php` and add:

```php
'ucp' => [
    'token_secret' => 'paste-generated-secret-here',
],
```

### Step 4 — Register your first agent in admin

Go to `Stores → Configuration → MSR → Agentic UCP → Agent registry`.

Click **Add agent** and fill in:

| Field | Example value | Notes |
|---|---|---|
| Agent name | My Shopping Agent | Human label |
| DID | `did:web:yourdomain.com` | From the agent owner |
| Trust level | Trusted | |
| Capability profile | Shopping agent | From dropdown |
| Active | Yes | |
| Max order value | 500 | Blank = use default |
| Rate limit/min | 30 | Blank = use default |
| Allowed payments | checkmo | Blank = all methods |

### Step 5 — Verify

```bash
curl -sk https://yourstore.com/.well-known/ucp.json | python3 -m json.tool
```

---

## DID — what to put in the admin config

| Situation | DID value |
|---|---|
| You host the agent | `did:web:yourdomain.com` |
| Agent on a subdomain | `did:web:agent.yourdomain.com` |
| Agent at a path | `did:web:yourdomain.com:agents:shopper` |
| Local dev / Warden | `did:web:default.freshm2.test:agents:test` |
| No server available | `did:key:z6Mk...` (generate below) |
| Third-party provider | Whatever DID they give you |

Generate a `did:key` instantly (no server needed):

```bash
npm install -g @digitalbazaar/did-key-cli
npx did-key generate --type Ed25519
```

Host a `did:web` on your domain — create and serve at
`https://yourdomain.com/.well-known/did.json`:

```json
{
  "@context": ["https://www.w3.org/ns/did/v1"],
  "id": "did:web:yourdomain.com",
  "verificationMethod": [{
    "id": "did:web:yourdomain.com#key-1",
    "type": "JsonWebKey2020",
    "controller": "did:web:yourdomain.com",
    "publicKeyPem": "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----"
  }],
  "authentication": ["did:web:yourdomain.com#key-1"],
  "assertionMethod": ["did:web:yourdomain.com#key-1"]
}
```

---

## Capability profiles

Profiles are defined in `etc/ucp.xml`. They are templates — real agents are
assigned to a profile in the admin panel.

### Built-in profiles

| Profile ID | What it allows |
|---|---|
| `profile-readonly` | Browse, search, check inventory, track orders |
| `profile-shopping` | Everything in readonly + manage cart, place orders |
| `profile-full-access` | Everything including customer data and payment initiation |

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

High-risk capabilities always require human confirmation regardless of the
default policy setting.

Capability code format: `namespace.action` — lowercase, dot-separated.
The XSD validates this pattern. Any code matching the pattern is valid —
no central registry needed.

---

## Policy middleware

Every `/V1/ucp/*` request (except `/V1/ucp/auth`) passes through five checks:

| Check | Failure |
|---|---|
| Valid Bearer token | 401 |
| Capability granted for this route | 403 |
| Rate limit not exceeded | 429 |
| Order value within limit (order routes only) | 422 |
| Human confirmation present (mutating requests) | 400 |

---

## Admin panel sections

### Agent registry

Dynamic grid — one row per AI agent. Profile dropdown is sourced from
`profile-*` entries in `ucp.xml` across all installed modules. Adding
a new module with a new profile automatically adds it to this dropdown.

### Capability profiles reference

Read-only table showing what each profile means in plain English.
Store owner sees "Browse catalog — View product listings", not `catalog.browse`.
Grouped by risk level.

### Default policies

Global defaults that apply to all agents unless overridden per-agent:
human confirmation, rate limit, max order value, audit log, token TTL.

### Security

Allowed DID methods, DID resolution timeout, and token secret status
indicator (shows configured/not configured without exposing the value).

### Recent audit log

Inline view of the last 20 agent requests showing agent, path,
capability (human label), outcome (colour-coded), and timestamp.

---

## Decision ownership

| What | Owned by | Changed via |
|---|---|---|
| Capability profiles | Code | `ucp.xml` + deploy |
| New capability codes | Code | `di.xml` injection + deploy |
| Agent identity (name, DID, trust level) | Database | Admin panel |
| Which agents are active | Database | Admin panel |
| Per-agent permissions | Database | Admin panel |
| Global default policies | Database | Admin panel |
| Token secret | Server | `env.php` |

Nothing about who the agents are or what their limits are should ever
require a code deployment.

---

## Extending from another module

### Add a new agent at runtime

Admin panel → Agent registry → Add agent. No code, no deployment.

### Add a new capability profile

Drop a `ucp.xml` with a `profile-*` entry. Appears in admin dropdown
after `cache:flush`. No base module changes.

```xml
<!-- YourVendor/YourModule/etc/ucp.xml -->
<config ...>
    <agent id="profile-b2b">
        <capabilities>
            <capability name="catalog.browse"     enabled="true"/>
            <capability name="quote.request"      enabled="true"/>
            <capability name="contract.negotiate" enabled="true"/>
        </capabilities>
    </agent>
</config>
```

### Add new capability codes

Inject into `Capabilities` source model via `di.xml`. No base module files
touched. New capabilities appear in the admin reference table and are
available in profile definitions.

```xml
<!-- YourVendor/YourModule/etc/di.xml -->
<type name="MSR\AgenticUcp\Model\Config\Source\Capabilities">
    <arguments>
        <argument name="additionalCapabilities" xsi:type="array">
            <item name="quote_request" xsi:type="array">
                <item name="value"   xsi:type="string">quote.request</item>
                <item name="label"   xsi:type="string">Request a quote</item>
                <item name="comment" xsi:type="string">Agent can request B2B price quotes</item>
                <item name="risk"    xsi:type="string">medium</item>
                <item name="module"  xsi:type="string">YourVendor_YourModule</item>
            </item>
        </argument>
    </arguments>
</type>
```

### When does a child module need ucp.xml?

```
New REST endpoints only       → NO  (no ucp.xml needed)
New capability codes          → YES (new profile-* entry + di.xml injection)
Only permissions/policies     → NO  (use admin panel)
Only identity/DID changes     → NO  (use admin panel)
```

### Add a custom policy check

```xml
<type name="MSR\AgenticUcp\Plugin\Webapi\AgentPolicyGuard">
    <plugin name="my_check" type="YourVendor\YourModule\Plugin\MyPlugin" sortOrder="20"/>
</type>
```

### Hook into auth events

```xml
<event name="ucp_agent_authenticated">
    <observer name="my_hook" instance="YourVendor\YourModule\Observer\OnAuth"/>
</event>
```



## Companion module

`MSR_AgenticUcpCheckout` provides catalog, cart, checkout, and order endpoints.
It does **not** need its own `ucp.xml` — it only adds REST routes for
capabilities already defined in base module profiles.

```bash
unzip MSR_AgenticUcpCheckout.zip -d /var/www/html/app/code/
php bin/magento module:enable MSR_AgenticUcpCheckout
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

---

## Local dev setup (Warden)

Apply dev mode patches using Claude CLI:

```bash
claude < claude_cli_dev_mode_prompt.md
```

Or manually patch `Model/Did/Resolver.php` to bypass HTTP DID resolution
and return a local key (see `UCP_Testing_Guide.md` for full instructions).

Revert before deploying:

```bash
grep -r "DEV MODE"     app/code/MSR/AgenticUcp/
grep -r "localDevKeys" app/code/MSR/AgenticUcp/
# Expected: empty output on both
```

---

## Troubleshooting

| Error | Cause | Fix |
|---|---|---|
| 404 on `/.well-known/ucp.json` | routes.xml has dot in frontName, or Router not registered | Check `frontName="ucpwellknown"` and `etc/frontend/di.xml` sortOrder=22 |
| "Agent not registered" | DID mismatch between admin config and request | Check admin panel DID matches POST body exactly |
| "Could not resolve DID document" | Resolver patch not applied (dev) or DID document unreachable (prod) | Apply dev patch or verify `https://yourdomain.com/.well-known/did.json` |
| "JWT issuer mismatch" | JWT `iss` claim doesn't match the `did` field | Decode JWT with `cut -d'.' -f2 \| base64 -d` and check `iss` |
| "UCP token signature invalid" | Token secret mismatch | Check `ucp/token_secret` in `env.php` matches test script |
| 404 instead of 401 on policy test | Checkout module not installed | Install `MSR_AgenticUcpCheckout` |
| "More than one node" XML merge error | Repeating elements missing merge key | Check `$_idAttributes` in `UcpReader.php` covers all list elements |
| New capability not in admin dropdown | `di.xml` injection not compiled | Run `setup:di:compile && cache:flush` |
| Rate limit not triggering | Redis not configured as cache backend | Check `env.php` cache section uses Redis |
