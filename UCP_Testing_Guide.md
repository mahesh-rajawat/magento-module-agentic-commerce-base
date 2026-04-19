# UCP Agentic Commerce — Complete Testing Guide

Everything you need to go from zero to a running AI agent
hitting your local Magento store via UCP.

---

## Overview

```
Phase A  →  Install both modules
Phase B  →  Admin panel — register your agent
Phase C  →  Generate DID + apply dev patches
Phase D  →  Configure + run the test scripts
Phase E  →  Full checkout flow test
Phase F  →  Live chat via Ollama or Open WebUI
```

---

## What changed from the original guide

The agent is no longer registered in ucp.xml. It is registered
in the Magento admin panel at runtime. ucp.xml now only contains
capability profiles (profile-readonly, profile-shopping etc).
The DID and all policies live in the database.

---

## Phase A — Install both modules

### A1. Requirements

| Requirement | Version |
|---|---|
| Magento | 2.4.4+ (Warden recommended for local) |
| PHP | 8.1 or 8.2 |
| MySQL | 8.0+ |
| Redis | 6.0+ (required for rate limiting) |
| OpenSSL PHP ext | enabled |

### A2. Install base module

```bash
unzip MSR_AgenticUcp.zip -d /var/www/html/app/code/
php bin/magento module:enable MSR_AgenticUcp
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
php bin/magento module:status MSR_AgenticUcp
```

### A3. Install checkout module

```bash
unzip MSR_AgenticUcpCheckout.zip -d /var/www/html/app/code/
php bin/magento module:enable MSR_AgenticUcpCheckout
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

### A4. Add token secret to env.php

```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

Add to `app/etc/env.php`:

```php
'ucp' => [
    'token_secret' => 'paste-generated-secret-here',
],
```

### A5. Verify discovery endpoint

```bash
curl -sk https://default.freshm2.test/.well-known/ucp.json | python3 -m json.tool
```

Expected: JSON manifest with empty agents array (agents added in Phase B).

If 404: check `etc/frontend/di.xml` has Router registered with sortOrder=22.

---

## Phase B — Admin panel setup

### B1. Configure store for guest orders

```
Stores → Config → Sales → Checkout → Allow Guest Checkout = Yes
Stores → Config → Sales → Shipping Methods → Flat Rate → Enabled = Yes
Stores → Config → Sales → Payment Methods → Check/Money Order → Enabled = Yes
php bin/magento cache:flush
```

### B2. Register your test agent

Go to `Stores → Configuration → MSR → Agentic UCP → Agent registry`.

Click **Add agent**:

| Field | Value |
|---|---|
| Agent name | Local Test Agent |
| DID | `did:web:default.freshm2.test:agents:test` |
| Trust level | Trusted |
| Capability profile | Shopping agent |
| Active | Yes |
| Max order value | 500 |
| Rate limit/min | 30 |
| Allowed payments | checkmo |

Save Config → `php bin/magento cache:flush`

### B3. Set default policies

`Stores → Config → MSR → Agentic UCP → Default policies`:

- Require human confirmation: Yes
- Rate limit/min: 30
- Max order value: 500
- Audit log: Yes
- Token TTL: 3600

### B4. Verify agent appears in manifest

```bash
curl -sk https://default.freshm2.test/.well-known/ucp.json | python3 -m json.tool
```

You should now see the agent in the `agents` array with capabilities listed.

---

## Phase C — Generate DID and apply dev patches

### C1. Generate a local key pair

```bash
mkdir -p ~/ucp-keys
openssl ecparam -name prime256v1 -genkey -noout -out ~/ucp-keys/agent-private.pem
openssl ec -in ~/ucp-keys/agent-private.pem -pubout -out ~/ucp-keys/agent-public.pem
cat ~/ucp-keys/agent-public.pem
```

### C2. Apply dev patches manually

Open `app/code/MSR/AgenticUcp/Model/Did/Resolver.php` and add
at the top of `resolvePublicKey()`:

```php
$localDevKeys = [
    'did:web:default.freshm2.test:agents:test' => <<<'PEM'
-----BEGIN PUBLIC KEY-----
PASTE OUTPUT OF: cat ~/ucp-keys/agent-public.pem
-----END PUBLIC KEY-----
PEM,
];
if (isset($localDevKeys[$did])) {
    $key = trim($localDevKeys[$did]);
    if (!str_contains($key, 'PASTE')) return $key;
}
```

```bash
php bin/magento setup:di:compile && php bin/magento cache:flush
```

### C4. Verify resolver works

```bash
php -r "
require '/var/www/html/app/bootstrap.php';
\$om = \Magento\Framework\App\Bootstrap::create(BP, \$_SERVER)->getObjectManager();
\$r  = \$om->get(\MSR\AgenticUcp\Model\Did\Resolver::class);
\$k  = \$r->resolvePublicKey('did:web:default.freshm2.test:agents:test');
echo \$k ? 'OK — key returned' . PHP_EOL : 'FAIL — still null' . PHP_EOL;
"
```

Expected: `OK — key returned`

---

## Phase D — Run policy and auth tests

### D1. Configure ucp_test.py

```python
MAGENTO_BASE         = "https://default.freshm2.test"
UCP_TOKEN_SECRET     = "your-secret-from-env-php"
TEST_DID             = "did:web:default.freshm2.test:agents:test"
ANTHROPIC_API_KEY    = "sk-ant-..."        # for Claude brain
DEFAULT_OLLAMA_MODEL = "llama3.2:latest"   # for Ollama brain
```

### D2. Install dependencies

```bash
pip install requests anthropic
```

### D3. Policy tests only (fastest, no AI)

```bash
python ucp_test.py --brain ollama --skip-agent
```

Runs: connectivity, discovery, auth, 4 policy checks, rate limit.

### D4. Full test with Claude brain

```bash
python ucp_test.py --brain claude
```

### D5. Full test with Ollama brain

```bash
ollama pull llama3.2:latest
ollama serve
python ucp_test.py --brain ollama --model llama3.2:latest

# Inside Warden Docker — Ollama is on host machine
python ucp_test.py --brain ollama \
  --ollama-host http://host.docker.internal:11434
```

### D6. What each test validates

| Test | Expected result |
|---|---|
| 0. Connectivity | HTTP 200, SSL valid |
| 1. Discovery | Manifest with agent and capabilities |
| 2. Auth | access_token returned |
| 3a. No token | 401 |
| 3b. Tampered token | 401 |
| 3c. Order over $500 | 422 |
| 3d. No confirmation header | 400 + instructions |
| 4. Rate limit (35 req) | 429 after 30th request |
| 5. AI agent loop | 4+ autonomous tool calls |

---

## Phase E — Full checkout flow test

### E1. Verify store has products

```bash
# Get a token first (run --skip-agent once to see the token in output)
# Then:
curl -sk https://default.freshm2.test/rest/V1/ucp/catalog \
  -H "Authorization: Bearer YOUR_TOKEN" | python3 -m json.tool
```

If empty: `Catalog → Products → Add Product` in admin.

### E2. Configure ucp_checkout_test.py

```python
MAGENTO_BASE         = "https://default.freshm2.test"
UCP_TOKEN_SECRET     = "your-secret-from-env-php"
TEST_DID             = "did:web:default.freshm2.test:agents:test"
TEST_SKU             = ""              # blank = auto-pick first product
TEST_EMAIL           = "agent@ucp.local"
TEST_SHIPPING_METHOD = "flatrate_flatrate"
TEST_PAYMENT_METHOD  = "checkmo"
```

### E3. Run manual mode first

```bash
python ucp_checkout_test.py --manual
```

Steps through all 13 stages with raw HTTP response at each step.
Fix any failures before running AI mode.

Expected successful run:
```
Step 1   Discover        ✓  manifest returned
Step 2   Authenticate    ✓  token received
Step 3   Browse          ✓  N products found
Step 4   Search          ✓  results returned
Step 5   Inventory       ✓  SKU in stock
Step 6   Add to cart     ✓  item added
Step 7   View cart       ✓  1 item
Step 8   Shipping methods ✓  flatrate available
Step 9   Set shipping    ✓  address set, totals collected
Step 10  Payment methods ✓  checkmo available
Step 11  Totals          ✓  grand total: USD XX.XX
Step 12  Place order     ✓  order #000000001 placed
Step 13  Track order     ✓  status: pending
```

### E4. Run AI-driven checkout

```bash
python ucp_checkout_test.py --brain claude
python ucp_checkout_test.py --brain ollama --model llama3.2:latest
```

### E5. Common checkout failures

| Symptom | Fix |
|---|---|
| Add to cart fails | Check product is In Stock in catalog |
| Empty shipping methods | Set shipping address first, enable Flat Rate |
| Order fails | Enable Guest Checkout, verify payment method active |
| Cart empty between requests | Check Redis running, try cache:flush |

---

## Phase F — Live chat via Ollama

Two identical chat clients are provided — use whichever runtime you prefer.
Both share the same config constants, CLI flags, tool set, and system prompt.

### F1. Python terminal chat

**Requirements:**
```bash
pip install requests
```

**Edit `ucp_chat.py`** — set the three constants at the top:
```python
MAGENTO_BASE     = "https://default.freshm2.test"
UCP_TOKEN_SECRET = "your-secret-from-env-php"
TEST_DID         = "did:web:default.freshm2.test:agents:test"
```

**Run:**
```bash
python ucp_chat.py
python ucp_chat.py --model llama3.1
python ucp_chat.py --model qwen2.5 --ollama-host http://host.docker.internal:11434
```

### F2. JavaScript terminal chat

**Requirements:** Node.js 18+ — no packages needed.

**Edit `ucp_chat.js`** — set the same three constants:
```js
let   MAGENTO_BASE     = 'https://default.freshm2.test';
const UCP_TOKEN_SECRET = 'your-secret-from-env-php';
const TEST_DID         = 'did:web:default.freshm2.test:agents:test';
```

**Run:**
```bash
node ucp_chat.js
node ucp_chat.js --model llama3.1
node ucp_chat.js --model qwen2.5 --ollama-host http://host.docker.internal:11434
```

SSL is resolved automatically from `REQUESTS_CA_BUNDLE`, `NODE_EXTRA_CA_CERTS`,
or `~/.warden/ssl/rootca/certs/ca.cert.pem` (Warden default). Falls back to
`rejectUnauthorized: false` if no CA file is found.

### F3. Example conversation

```
You: show me products under $30
You: add the cheapest shirt to my cart
You: ship to Austin TX, use flat rate
You: what is my total?
You: place the order, email me@example.com
```

### F4. CLI flags (both clients)

| Flag | Default | Description |
|---|---|---|
| `--model NAME` | `qwen2.5` | Ollama model to use |
| `--ollama-host URL` | `http://localhost:11434` | Ollama API base URL |
| `--magento URL` | value in script | Override Magento base URL |

### F5. Open WebUI (visual interface)

```bash
docker run -d --network=host \
  -v open-webui:/app/backend/data \
  -e OLLAMA_BASE_URL=http://localhost:11434 \
  --name open-webui \
  ghcr.io/open-webui/open-webui:main
```

Open `http://localhost:8080`, go to `Workspace → Tools → + New Tool`,
paste the UCP tool plugin, set `MAGENTO_BASE` and `UCP_TOKEN_SECRET`.

### F6. Model recommendations

| Model | Size | Tool use |
|---|---|---|
| `qwen2.5:7b` | 4.4GB | Best overall |
| `llama3.1:8b` | 4.7GB | Good |
| `mistral:7b` | 4.1GB | Fast |
| `llama3.2:3b` | 2.0GB | Basic — limited RAM only |

---

## Verify the audit log

```bash
warden shell
mysql -u magento -pmagento magento -e "
  SELECT agent_did, request_path, capability_label, outcome, created_at
  FROM ucp_audit_log
  ORDER BY created_at DESC LIMIT 20;
"
```

`capability_label` shows human names (Browse catalog, not catalog.browse).

---

## Revert dev patches before production

```bash
grep -r "DEV MODE"     app/code/MSR/AgenticUcp/
grep -r "localDevKeys" app/code/MSR/AgenticUcp/
# Expected: empty output — no dev code remains
```

---

## Production deployment checklist

```
[ ] Dev patches reverted (grep confirms empty)
[ ] Agent DID updated in admin panel to real domain DID
[ ] Token secret is strong (32+ random bytes in env.php)
[ ] Agent uses ES256 (not HS256) JWT signing
[ ] did.json hosted at https://yourdomain.com/.well-known/did.json
[ ] Rate limits appropriate for production traffic
[ ] Human confirmation enabled for high-risk capabilities
[ ] Redis configured as cache backend
[ ] Audit log enabled
```

---

## Quick reference — all commands

```bash
# Install
php bin/magento module:enable MSR_AgenticUcp MSR_AgenticUcpCheckout
php bin/magento setup:upgrade && php bin/magento setup:di:compile
php bin/magento cache:flush

# Generate keys
openssl ecparam -name prime256v1 -genkey -noout -out ~/ucp-keys/agent-private.pem
openssl ec -in ~/ucp-keys/agent-private.pem -pubout -out ~/ucp-keys/agent-public.pem

# Smoke test
curl -sk https://default.freshm2.test/.well-known/ucp.json | python3 -m json.tool

# Policy tests only
python ucp_test.py --brain ollama --skip-agent

# Full test — Claude
python ucp_test.py --brain claude

# Full test — Ollama
ollama pull llama3.1 && ollama serve
python ucp_test.py --brain ollama --model llama3.1

# Checkout flow — manual
python ucp_checkout_test.py --manual

# Checkout flow — AI
python ucp_checkout_test.py --brain claude

# Terminal chat — Python (pip install requests)
python ucp_chat.py
python ucp_chat.py --model llama3.1 --ollama-host http://host.docker.internal:11434

# Terminal chat — JavaScript (Node 18+, no packages)
node ucp_chat.js
node ucp_chat.js --model llama3.1 --ollama-host http://host.docker.internal:11434

# Audit log
mysql -u magento -pmagento magento \
  -e "SELECT * FROM ucp_audit_log ORDER BY created_at DESC LIMIT 20;"

# Verify clean before deploy
grep -r "DEV MODE" app/code/MSR/AgenticUcp/
```

---

## Troubleshooting quick reference

| Error | Cause | Fix |
|---|---|---|
| 404 `/.well-known/ucp.json` | Router not registered | Check `etc/frontend/di.xml` sortOrder=22 |
| Empty agents in manifest | Agent not in admin panel | Stores → Config → MSR → Agentic UCP → Add agent |
| "Agent not registered" | DID mismatch | Admin panel DID must match TEST_DID exactly |
| "Could not resolve DID" | Resolver patch missing or key not pasted | Check `$localDevKeys` has real key |
| "JWT issuer mismatch" | Wrong iss in JWT | Decode: `cut -d'.' -f2 \| base64 -d` check `iss` |
| "Token signature invalid" | Secret mismatch | env.php secret must match UCP_TOKEN_SECRET |
| 404 on `/V1/ucp/catalog` | Checkout module not installed | Install MSR_AgenticUcpCheckout |
| 404 instead of 401 | Same as above | Routes only exist in checkout module |
| "More than one node" XML | Duplicate agent in child ucp.xml | Remove agent from child module's ucp.xml |
| Rate limit not triggering | Redis not configured | Check env.php cache section |
| SSL error (Python) | Warden self-signed cert | `export REQUESTS_CA_BUNDLE=~/.warden/ssl/rootca/certs/ca.cert.pem` |
| SSL error (Node.js) | Warden self-signed cert | `export NODE_EXTRA_CA_CERTS=~/.warden/ssl/rootca/certs/ca.cert.pem` |
| Ollama unreachable | Docker networking | `--ollama-host http://host.docker.internal:11434` |
