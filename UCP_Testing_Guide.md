# UCP Agentic Commerce — Complete Testing Guide

Everything you need to go from zero to a running AI agent
hitting your local Magento store via UCP.

---

## Overview

```
Phase A  →  Set up Magento + install module
Phase B  →  Generate DID + register it
Phase C  →  Configure + run the test script
Phase D  →  Test with Claude or Ollama as the agent brain
```

---

## Phase A — Magento local setup

### A1. Requirements

| Requirement      | Version      |
|------------------|--------------|
| PHP              | 8.1 or 8.2   |
| MySQL            | 8.0+         |
| Redis            | 6.0+ (for rate limiting) |
| Nginx / Apache   | any          |
| Composer         | 2.x          |
| OpenSSL PHP ext  | enabled      |

### A2. Install the module

```bash
# 1. Unzip the module into Magento
unzip Vendor_AgenticUcp.zip -d /var/www/magento/app/code/

# 2. Enable and compile
cd /var/www/magento
php bin/magento module:enable Vendor_AgenticUcp
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush

# 3. Verify module is active
php bin/magento module:status Vendor_AgenticUcp
# Expected: Module is enabled
```

### A3. Add token secret to env.php

Generate a strong secret:
```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
# Example output: 3f8a2c1d9e4b7f6a0d5c8e2b1a9f3d7c...
```

Open `app/etc/env.php` and add this block:
```php
'ucp' => [
    'token_secret' => '3f8a2c1d9e4b7f6a0d5c8e2b1a9f3d7c...',  // your generated value
],
```

Then flush cache:
```bash
php bin/magento cache:flush
```

### A4. Quick smoke test

```bash
curl -s http://localhost/.well-known/ucp.json | python3 -m json.tool
```

Expected response:
```json
{
  "version": "1.0",
  "store_url": "http://localhost",
  "auth_endpoint": "/rest/V1/ucp/auth",
  "api_base": "/rest/V1/ucp",
  "agents": [...],
  "capabilities_offered": [...]
}
```

If this works, Phase A is done.

---

## Phase B — Generate your DID and register it

A DID is just a key pair + a publicly accessible JSON file.
For local testing you do NOT need a public server — we patch
the resolver to skip HTTP and use a local key directly.

### B1. Generate a key pair

```bash
# Create directory for your keys
mkdir -p ~/ucp-keys && cd ~/ucp-keys

# Generate EC P-256 private key
openssl ecparam -name prime256v1 -genkey -noout -out agent-private.pem

# Extract the public key
openssl ec -in agent-private.pem -pubout -out agent-public.pem

# View keys (you'll need these in the next steps)
echo "=== PRIVATE KEY ===" && cat agent-private.pem
echo "=== PUBLIC KEY ===" && cat agent-public.pem
```

Keep `agent-private.pem` safe — never commit it to git.

### B2. Create your DID document (for future production use)

This is what you'd host at `https://yourdomain.com/.well-known/did.json`:

```json
{
  "@context": [
    "https://www.w3.org/ns/did/v1",
    "https://w3id.org/security/suites/jws-2020/v1"
  ],
  "id": "did:web:yourdomain.com",
  "verificationMethod": [
    {
      "id": "did:web:yourdomain.com#key-1",
      "type": "JsonWebKey2020",
      "controller": "did:web:yourdomain.com",
      "publicKeyPem": "-----BEGIN PUBLIC KEY-----\nPASTE YOUR agent-public.pem CONTENT HERE\n-----END PUBLIC KEY-----"
    }
  ],
  "authentication": ["did:web:yourdomain.com#key-1"],
  "assertionMethod": ["did:web:yourdomain.com#key-1"]
}
```

For local testing this file is not needed — see B3.

### B3. Patch the DID resolver for local dev

Because you don't have a live domain locally, we tell Magento
to use your key directly without HTTP resolution.

Open this file:
```
app/code/Vendor/AgenticUcp/Model/Did/Resolver.php
```

Replace the `resolvePublicKey()` method with:

```php
public function resolvePublicKey(string $did): ?string
{
    // LOCAL DEV ONLY — hardcoded key map, skips HTTP fetch
    // Remove this block before deploying to production!
    $localKeys = [
        'did:web:localhost:agents:test' => <<<'PEM'
-----BEGIN PUBLIC KEY-----
PASTE THE FULL CONTENT OF ~/ucp-keys/agent-public.pem HERE
(including the BEGIN/END lines and all base64 content)
-----END PUBLIC KEY-----
PEM,
    ];

    if (isset($localKeys[$did])) {
        return trim($localKeys[$did]);
    }

    // Production: real DID document HTTP resolution
    if (!str_starts_with($did, 'did:web:')) {
        return null;
    }
    $host = str_replace(['did:web:', ':'], ['', '/'], $did);
    $url  = "https://{$host}/.well-known/did.json";
    try {
        $this->curl->setTimeout(5);
        $this->curl->get($url);
        if ($this->curl->getStatus() !== 200) return null;
        $doc = json_decode($this->curl->getBody(), true);
        foreach ($doc['verificationMethod'] ?? [] as $method) {
            if (!empty($method['publicKeyPem'])) return $method['publicKeyPem'];
        }
    } catch (\Exception) {
        return null;
    }
    return null;
}
```

Then flush:
```bash
php bin/magento cache:flush
php bin/magento setup:di:compile
```

### B4. Register the DID in ucp.xml

Open `app/code/Vendor/AgenticUcp/etc/ucp.xml` and confirm
the `<did>` tag matches exactly what you put in `$localKeys`:

```xml
<agent id="claude-shopping-agent" active="true">
    <identity>
        <name>Claude Agentic Shopper</name>
        <did>did:web:localhost:agents:test</did>   <!-- must match $localKeys key -->
        <trust_level>trusted</trust_level>
    </identity>
    ...
</agent>
```

Flush cache again:
```bash
php bin/magento cache:flush
```

### B5. Verify auth works manually

```bash
# Generate a test JWT with your key
php -r "
\$private = file_get_contents('/root/ucp-keys/agent-private.pem');
\$did     = 'did:web:localhost:agents:test';
\$now     = time();
\$b64u    = fn(\$d) => rtrim(strtr(base64_encode(\$d), '+/', '-_'), '=');
\$h = \$b64u(json_encode(['alg'=>'ES256','typ'=>'JWT']));
\$p = \$b64u(json_encode(['iss'=>\$did,'aud'=>'http://localhost','iat'=>\$now,'exp'=>\$now+300]));
\$key = openssl_pkey_get_private(\$private);
openssl_sign(\"\$h.\$p\", \$sig, \$key, OPENSSL_ALGO_SHA256);
echo \"\$h.\$p.\".\$b64u(\$sig).PHP_EOL;
"
```

Copy the output JWT and use it:

```bash
curl -s -X POST http://localhost/rest/V1/ucp/auth \
  -H "Content-Type: application/json" \
  -d '{
    "request": {
      "did": "did:web:localhost:agents:test",
      "signed_jwt": "PASTE_JWT_HERE",
      "requested_capabilities": ["catalog.browse", "cart.manage"]
    }
  }' | python3 -m json.tool
```

Expected response:
```json
{
  "access_token": "eyJhbGci...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "granted_capabilities": ["catalog.browse", "cart.manage"]
}
```

Phase B is done when you see the access token.

---

## Phase C — Configure the test script

Open `ucp_test.py` and set these three values at the top:

```python
MAGENTO_BASE     = "http://localhost"        # your local Magento URL
UCP_TOKEN_SECRET = "3f8a2c1d9e4..."          # from app/etc/env.php → ucp.token_secret
TEST_DID         = "did:web:localhost:agents:test"  # must match ucp.xml <did>
```

For Claude brain, also set:
```python
ANTHROPIC_API_KEY = "sk-ant-api03-..."       # from console.anthropic.com
```

For Ollama brain, also set:
```python
OLLAMA_BASE          = "http://localhost:11434"
DEFAULT_OLLAMA_MODEL = "llama3.1"
```

Install dependencies:
```bash
# For Claude brain
pip install anthropic requests

# For Ollama brain only
pip install requests
```

---

## Phase D — Run the tests

### Option 1: Claude as the agent brain

```bash
python ucp_test.py --brain claude
```

Claude uses native tool-calling — it receives the UCP tools as
a structured list, decides which ones to call, and interprets
the responses. Most reliable for testing.

### Option 2: Ollama as the agent brain (fully local, no internet)

```bash
# Step 1: Pull a model (one time only)
ollama pull llama3.1        # 4.7GB — best tool use
# OR
ollama pull mistral         # 4.1GB — lighter
# OR
ollama pull qwen2.5         # 4.7GB — good for non-English

# Step 2: Start Ollama
ollama serve

# Step 3: Run with Ollama brain
python ucp_test.py --brain ollama
python ucp_test.py --brain ollama --model mistral
```

### Option 3: Skip the AI loop, just test policies

```bash
python ucp_test.py --brain claude --skip-agent
```

Useful for fast CI checks — runs discovery, auth, policy checks,
and rate limit test only. No API tokens consumed.

---

## What each test checks

| Test | What it validates |
|------|-------------------|
| 1. Discovery | `/.well-known/ucp.json` returns valid manifest |
| 2. Auth | DID + JWT → scoped Bearer token issued |
| 3a. No token | Returns 401 |
| 3b. Tampered token | Returns 401 |
| 3c. Order over limit | Returns 422 (max_order_value guard) |
| 3d. No confirmation | Returns confirmation-required message |
| 4. Rate limit | 429 triggered after 30 requests/min |
| 5. AI agent loop | LLM autonomously calls UCP tools 4–8 times |

---

## Troubleshooting

### "Agent not registered"
- Check `<did>` in `ucp.xml` exactly matches `TEST_DID` in `ucp_test.py`
- Run `php bin/magento cache:flush`

### "Could not resolve DID document"
- The Resolver patch was not applied, or the key in `$localKeys` is wrong
- Paste the full public key content including `-----BEGIN/END PUBLIC KEY-----`

### "UCP token signature invalid"
- `UCP_TOKEN_SECRET` in `ucp_test.py` does not match `ucp.token_secret` in `env.php`
- Copy the exact value — no extra spaces or newlines

### "Cannot connect to Magento"
- Check Magento is running: `curl http://localhost/`
- Check your `MAGENTO_BASE` URL (no trailing slash)

### Rate limit not triggering
- Redis must be running and configured as Magento's cache backend
- Check `app/etc/env.php` → `cache` section uses Redis
- Verify `rate_limit_per_minute` is set in `ucp.xml`

### Ollama model not found
```bash
ollama list            # see pulled models
ollama pull llama3.1   # pull if missing
```

### Ollama tool loop gives random JSON
- Switch to a better model: `--model qwen2.5` or `--model llama3.1:70b`
- Or use `--brain claude` for reliable tool use

---

## Verify the audit log

After running the tests, check what was recorded:

```sql
-- Connect to Magento database
SELECT agent_did, request_path, capability, outcome, created_at
FROM ucp_audit_log
ORDER BY created_at DESC
LIMIT 20;
```

You should see rows with outcome = ALLOWED, DENIED, and RATE_LIMITED.

---

## Production DID registration (when you go live)

When you're ready to deploy to a real server:

1. Host `did.json` at `https://yourdomain.com/.well-known/did.json`
   (use the template from B2 above)

2. Remove the `$localKeys` block from `Resolver.php`

3. Update `ucp.xml` with the real DID:
   ```xml
   <did>did:web:yourdomain.com</did>
   ```

4. Use ES256 (not HS256) JWT signing with your private key

5. Use a DID registry service if you want discoverability:
   - Spruce ID: https://spruceid.com
   - uniresolver.io: resolves any DID method
   - Walt.id: https://walt.id (hosted DID management)

---

## Quick reference: all commands

```bash
# Install module
php bin/magento module:enable Vendor_AgenticUcp
php bin/magento setup:upgrade && php bin/magento setup:di:compile
php bin/magento cache:flush

# Generate keys
openssl ecparam -name prime256v1 -genkey -noout -out agent-private.pem
openssl ec -in agent-private.pem -pubout -out agent-public.pem

# Smoke test discovery
curl -s http://localhost/.well-known/ucp.json | python3 -m json.tool

# Run tests (Claude brain)
pip install anthropic requests
python ucp_test.py --brain claude

# Run tests (Ollama brain)
ollama pull llama3.1 && ollama serve
pip install requests
python ucp_test.py --brain ollama

# Policy tests only (no AI loop)
python ucp_test.py --brain claude --skip-agent

# Audit log
mysql -u root magento -e "SELECT * FROM ucp_audit_log ORDER BY created_at DESC LIMIT 20;"
```
