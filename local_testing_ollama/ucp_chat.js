#!/usr/bin/env node
/**
 * UCP Terminal Chat
 * =================
 * Conversational chat with Ollama that calls your Magento UCP
 * endpoints as tools in real time.
 *
 * Usage:
 *   node ucp_chat.js
 *   node ucp_chat.js --model qwen2.5
 *   node ucp_chat.js --model llama3.1 --ollama-host http://host.docker.internal:11434
 *
 * Requires Node.js 18+ — no extra packages.
 * Type naturally — the model decides which tools to call.
 * Type 'quit' or Ctrl+C to exit.
 */

'use strict';

const crypto   = require('crypto');
const https    = require('https');
const http     = require('http');
const fs       = require('fs');
const path     = require('path');
const readline = require('readline');
const os       = require('os');

// ─────────────────────────────────────────────────────────────────────────────
// CONFIG — edit these
// ─────────────────────────────────────────────────────────────────────────────

let   MAGENTO_BASE     = 'https://default.freshm2.test';
const UCP_TOKEN_SECRET = '7d5f7cfffa2e773a1344ec4d119f380787f14b775e1bb5524278f24e54c7a102';
const TEST_DID         = 'did:web:default.freshm2.test:agents:test';
let   OLLAMA_BASE      = 'http://localhost:11434';
const DEFAULT_MODEL    = 'qwen2.5';

// ─────────────────────────────────────────────────────────────────────────────
// SSL — Warden CA auto-detection
// ─────────────────────────────────────────────────────────────────────────────

function resolveCA() {
    const envCa    = process.env.REQUESTS_CA_BUNDLE || process.env.NODE_EXTRA_CA_CERTS;
    const wardenCa = path.join(os.homedir(), '.warden/ssl/rootca/certs/ca.cert.pem');
    if (envCa    && fs.existsSync(envCa))    return fs.readFileSync(envCa);
    if (fs.existsSync(wardenCa))             return fs.readFileSync(wardenCa);
    return null;
}

const CA_CERT = resolveCA();

// ─────────────────────────────────────────────────────────────────────────────
// Terminal colours
// ─────────────────────────────────────────────────────────────────────────────

const C = {
    RESET:  '\x1b[0m',  BOLD:   '\x1b[1m',
    GREEN:  '\x1b[92m', YELLOW: '\x1b[93m',
    CYAN:   '\x1b[96m', PURPLE: '\x1b[95m',
    GRAY:   '\x1b[90m', RED:    '\x1b[91m',
};

// ─────────────────────────────────────────────────────────────────────────────
// HTTP — zero-dependency wrapper around Node's http/https modules
// ─────────────────────────────────────────────────────────────────────────────

function request(url, { method = 'GET', headers = {}, body = null } = {}) {
    return new Promise((resolve, reject) => {
        const u   = new URL(url);
        const mod = u.protocol === 'https:' ? https : http;
        const opts = {
            hostname: u.hostname,
            port:     u.port || (u.protocol === 'https:' ? 443 : 80),
            path:     u.pathname + u.search,
            method,
            headers,
        };
        if (u.protocol === 'https:') {
            if (CA_CERT) opts.ca = CA_CERT;
            else         opts.rejectUnauthorized = false;
        }
        const req = mod.request(opts, res => {
            const chunks = [];
            res.on('data', c => chunks.push(c));
            res.on('end', () => {
                const text = Buffer.concat(chunks).toString();
                resolve({
                    status: res.statusCode,
                    ok:     res.statusCode >= 200 && res.statusCode < 300,
                    json:   () => JSON.parse(text),
                    text:   () => text,
                });
            });
        });
        req.on('error', reject);
        if (body) req.write(body);
        req.end();
    });
}

// ─────────────────────────────────────────────────────────────────────────────
// JWT + auth helpers
// ─────────────────────────────────────────────────────────────────────────────

function b64u(data) {
    const buf = typeof data === 'string' ? Buffer.from(data) : data;
    return buf.toString('base64url');
}

function makeJwt(did, secret, ttl = 3600) {
    const now = Math.floor(Date.now() / 1000);
    const h   = b64u(JSON.stringify({ alg: 'HS256', typ: 'JWT' }));
    const p   = b64u(JSON.stringify({
        iss: did,
        aud: MAGENTO_BASE,
        iat: now,
        exp: now + ttl,
    }));
    const sig = b64u(crypto.createHmac('sha256', secret).update(`${h}.${p}`).digest());
    return `${h}.${p}.${sig}`;
}

// Token cache — re-auth automatically when expired
const _tokenCache = { token: null, exp: 0 };

async function getToken() {
    if (_tokenCache.token && Date.now() / 1000 < _tokenCache.exp - 60) {
        return _tokenCache.token;
    }
    const r = await request(`${MAGENTO_BASE}/rest/V1/ucp/auth`, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ request: {
            did:                    TEST_DID,
            signed_jwt:             makeJwt(TEST_DID, UCP_TOKEN_SECRET),
            requested_capabilities: [
                'catalog.browse', 'catalog.search',
                'cart.manage',    'order.place',
                'order.track',    'inventory.query',
            ],
        }}),
    });
    if (!r.ok) {
        console.error(`${C.RED}Auth failed: ${r.text().slice(0, 200)}${C.RESET}`);
        process.exit(1);
    }
    const data = r.json();
    _tokenCache.token = data.access_token;
    _tokenCache.exp   = Date.now() / 1000 + (data.expires_in ?? 3600);
    return _tokenCache.token;
}

async function _headers(confirm = false) {
    const h = {
        'Authorization': `Bearer ${await getToken()}`,
        'Content-Type':  'application/json',
    };
    if (confirm) {
        h['X-UCP-Human-Confirmation'] = b64u(`confirm-${Math.floor(Date.now() / 1000)}`);
    }
    return h;
}

async function _get(apiPath) {
    const r = await request(`${MAGENTO_BASE}/rest${apiPath}`, {
        headers: await _headers(),
    });
    try { return r.json(); } catch { return { error: r.text(), status: r.status }; }
}

async function _post(apiPath, body, confirm = false) {
    const r = await request(`${MAGENTO_BASE}/rest${apiPath}`, {
        method:  'POST',
        headers: await _headers(confirm),
        body:    JSON.stringify(body),
    });
    try { return r.json(); } catch { return { error: r.text(), status: r.status }; }
}

// ─────────────────────────────────────────────────────────────────────────────
// Tool definitions — Ollama OpenAI-style function calling format
// ─────────────────────────────────────────────────────────────────────────────

const TOOLS = [
    {
        type: 'function',
        function: {
            name: 'discover_store',
            description: 'Discover what the store supports — capabilities, agents, auth endpoint. Call this first.',
            parameters: { type: 'object', properties: {} },
        },
    },
    {
        type: 'function',
        function: {
            name: 'browse_catalog',
            description: 'Browse available products. Returns name, SKU, price, stock status.',
            parameters: {
                type: 'object',
                properties: {
                    page_size: { type: 'integer', description: 'Products per page (default 10)' },
                },
            },
        },
    },
    {
        type: 'function',
        function: {
            name: 'search_catalog',
            description: 'Search products by keyword. Use this to find specific items.',
            parameters: {
                type: 'object',
                properties: {
                    query: { type: 'string', description: 'Search keyword e.g. shirt, laptop' },
                },
                required: ['query'],
            },
        },
    },
    {
        type: 'function',
        function: {
            name: 'check_inventory',
            description: 'Check if a specific SKU is in stock and how many are available.',
            parameters: {
                type: 'object',
                properties: {
                    sku: { type: 'string', description: 'Product SKU to check' },
                },
                required: ['sku'],
            },
        },
    },
    {
        type: 'function',
        function: {
            name: 'view_cart',
            description: 'View the current cart contents, item list, and totals.',
            parameters: { type: 'object', properties: {} },
        },
    },
    {
        type: 'function',
        function: {
            name: 'add_to_cart',
            description: 'Add a product to the cart by SKU and quantity.',
            parameters: {
                type: 'object',
                properties: {
                    sku: { type: 'string',  description: 'Product SKU' },
                    qty: { type: 'integer', description: 'Quantity to add (default 1)' },
                },
                required: ['sku'],
            },
        },
    },
    {
        type: 'function',
        function: {
            name: 'remove_from_cart',
            description: 'Remove a specific item from the cart by its cart item ID.',
            parameters: {
                type: 'object',
                properties: {
                    item_id: { type: 'integer', description: 'Cart item ID from view_cart response' },
                },
                required: ['item_id'],
            },
        },
    },
    {
        type: 'function',
        function: {
            name: 'get_shipping_methods',
            description: 'Get available shipping methods and their costs for the current cart.',
            parameters: { type: 'object', properties: {} },
        },
    },
    {
        type: 'function',
        function: {
            name: 'set_shipping',
            description: (
                'Set the shipping address, delivery method, and optionally billing address. ' +
                'Pass billing_same_as_shipping=true (default) to copy shipping to billing automatically — ' +
                'only set it to false if the customer explicitly wants a different billing address.'
            ),
            parameters: {
                type: 'object',
                properties: {
                    firstname:                { type: 'string' },
                    lastname:                 { type: 'string' },
                    street:                   { type: 'string' },
                    city:                     { type: 'string' },
                    region_code:              { type: 'string', description: 'Ask the customer for their state/region' },
                    postcode:                 { type: 'string' },
                    country_id:               { type: 'string', description: '2-letter country e.g. US' },
                    telephone:                { type: 'string' },
                    shipping_method_code:     { type: 'string', description: 'e.g. flatrate_flatrate' },
                    billing_same_as_shipping: { type: 'boolean', description: 'Default true — set false only if customer wants different billing address' },
                },
                required: ['firstname', 'lastname', 'street', 'city',
                           'region_code', 'postcode', 'country_id',
                           'telephone', 'shipping_method_code'],
            },
        },
    },
    {
        type: 'function',
        function: {
            name: 'set_billing',
            description: 'Set a separate billing address (only needed when different from shipping).',
            parameters: {
                type: 'object',
                properties: {
                    firstname:   { type: 'string' },
                    lastname:    { type: 'string' },
                    street:      { type: 'string' },
                    city:        { type: 'string' },
                    region_code: { type: 'string' },
                    postcode:    { type: 'string' },
                    country_id:  { type: 'string' },
                    telephone:   { type: 'string' },
                },
                required: ['firstname', 'lastname', 'street', 'city',
                           'region_code', 'postcode', 'country_id', 'telephone'],
            },
        },
    },
    {
        type: 'function',
        function: {
            name: 'get_totals',
            description: 'Get the cart totals including subtotal, shipping, tax, and grand total.',
            parameters: { type: 'object', properties: {} },
        },
    },
    {
        type: 'function',
        function: {
            name: 'get_payment_methods',
            description: 'Get available payment methods for the current cart.',
            parameters: { type: 'object', properties: {} },
        },
    },
    {
        type: 'function',
        function: {
            name: 'place_order',
            description: 'Place the order. Only call this when the customer explicitly confirms they want to buy.',
            parameters: {
                type: 'object',
                properties: {
                    payment_method_code: { type: 'string', description: 'e.g. checkmo or free' },
                    email:               { type: 'string', description: 'Customer email for order confirmation' },
                },
                required: ['payment_method_code', 'email'],
            },
        },
    },
    {
        type: 'function',
        function: {
            name: 'track_order',
            description: 'Track an existing order by its increment ID to see status and shipping info.',
            parameters: {
                type: 'object',
                properties: {
                    order_id: { type: 'string', description: 'Order increment ID e.g. 000000001' },
                },
                required: ['order_id'],
            },
        },
    },
];

// ─────────────────────────────────────────────────────────────────────────────
// Tool executor
// ─────────────────────────────────────────────────────────────────────────────

async function runTool(name, args) {
    process.stdout.write(`${C.GRAY}  [calling ${name}(${JSON.stringify(args)})]${C.RESET}\n`);
    try {
        switch (name) {
            case 'discover_store': {
                const r = await request(`${MAGENTO_BASE}/.well-known/ucp.json`);
                return JSON.stringify(r.json(), null, 2);
            }
            case 'browse_catalog': {
                const ps = args.page_size ?? 10;
                return JSON.stringify(await _get(`/V1/ucp/catalog?pageSize=${ps}`), null, 2);
            }
            case 'search_catalog': {
                return JSON.stringify(await _get(`/V1/ucp/search?q=${encodeURIComponent(args.query ?? '')}`), null, 2);
            }
            case 'check_inventory': {
                return JSON.stringify(await _get(`/V1/ucp/inventory?sku=${encodeURIComponent(args.sku)}`), null, 2);
            }
            case 'view_cart': {
                return JSON.stringify(await _get('/V1/ucp/cart'), null, 2);
            }
            case 'add_to_cart': {
                return JSON.stringify(await _post('/V1/ucp/cart', {
                    sku: args.sku,
                    qty: args.qty ?? 1,
                }), null, 2);
            }
            case 'remove_from_cart': {
                const r = await request(
                    `${MAGENTO_BASE}/rest/V1/ucp/cart/${args.item_id}`,
                    { method: 'DELETE', headers: await _headers() },
                );
                try { return JSON.stringify(r.json(), null, 2); }
                catch { return JSON.stringify({ status: r.status }); }
            }
            case 'get_shipping_methods': {
                return JSON.stringify(await _get('/V1/ucp/checkout/shipping-methods'), null, 2);
            }
            case 'set_shipping': {
                return JSON.stringify(await _post('/V1/ucp/checkout/shipping', args), null, 2);
            }
            case 'set_billing': {
                return JSON.stringify(await _post('/V1/ucp/checkout/billing', {
                    firstname:   args.firstname,
                    lastname:    args.lastname,
                    street:      args.street,
                    city:        args.city,
                    region_code: args.region_code,
                    postcode:    args.postcode,
                    country_id:  args.country_id,
                    telephone:   args.telephone,
                }), null, 2);
            }
            case 'get_totals': {
                return JSON.stringify(await _get('/V1/ucp/checkout/totals'), null, 2);
            }
            case 'get_payment_methods': {
                return JSON.stringify(await _get('/V1/ucp/checkout/payment-methods'), null, 2);
            }
            case 'place_order': {
                return JSON.stringify(await _post('/V1/ucp/order', args, true), null, 2);
            }
            case 'track_order': {
                return JSON.stringify(await _get(`/V1/ucp/order/${args.order_id}`), null, 2);
            }
            default:
                return JSON.stringify({ error: `Unknown tool: ${name}` });
        }
    } catch (e) {
        if (e.code === 'ECONNREFUSED') {
            return JSON.stringify({ error: `Cannot connect to Magento at ${MAGENTO_BASE}` });
        }
        return JSON.stringify({ error: e.message });
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Ollama chat loop
// ─────────────────────────────────────────────────────────────────────────────

const SYSTEM = `You are a helpful AI shopping assistant for a Magento store.
You have tools to browse products, manage a cart, and place orders.

Guidelines:
- Be conversational and friendly
- NEVER invent product names, prices, or SKUs — always call browse_catalog or search_catalog first
- Always check inventory before adding to cart
- Show the cart total before asking about placing an order
- Always ask the customer for shipping details in order like: full name, street address, city, state/region, postal code, country and telephone, and call set_shipping before place_order
- Always ask customer email before placing an order for the confirmation email
- Always confirm with the customer before calling place_order
- Keep responses concise — one or two sentences after tool results

Billing address rules:
- When calling set_shipping, ALWAYS pass billing_same_as_shipping=true unless the customer
  explicitly says their billing address is different from their shipping address
- Only call set_billing if the customer says they have a different billing address
- Never ask the customer for billing address separately — assume same as shipping by default

Pricing rules:
- Always use the 'price' field as the current price for qty=1
- If 'special_price' is set, mention it's on sale and show the original 'regular_price'
- If 'tier_prices' is not empty, mention the quantity discounts
- Use 'price_summary' for a ready-made accurate price description
- NEVER make up or guess prices — only use what the tool returns`;

async function chat(model, ollamaBase) {
    const messages = [{ role: 'system', content: SYSTEM }];

    console.log(`
${C.BOLD}${C.PURPLE}UCP Magento Chat${C.RESET}
${C.GRAY}Model: ${model}  |  Store: ${MAGENTO_BASE}${C.RESET}
${C.GRAY}─────────────────────────────────────────────${C.RESET}
${C.GRAY}Try: "show me products"  |  "add X to cart"  |  "what's my total"${C.RESET}
${C.GRAY}Type 'quit' to exit${C.RESET}
`);

    process.stdout.write(`${C.GRAY}Authenticating...${C.RESET} `);
    try {
        await getToken();
        console.log(`${C.GREEN}ready${C.RESET}\n`);
    } catch (e) {
        console.log(`${C.RED}failed: ${e.message}${C.RESET}`);
        process.exit(1);
    }

    const rl = readline.createInterface({ input: process.stdin, output: process.stdout });

    const prompt = () => new Promise(resolve => rl.question(`${C.CYAN}You:${C.RESET} `, resolve));

    rl.on('close', () => {
        console.log(`\n${C.GRAY}Bye!${C.RESET}`);
        process.exit(0);
    });

    while (true) {
        let user;
        try {
            user = (await prompt()).trim();
        } catch {
            console.log(`\n${C.GRAY}Bye!${C.RESET}`);
            break;
        }

        if (!user) continue;
        if (['quit', 'exit', 'q'].includes(user.toLowerCase())) {
            console.log(`${C.GRAY}Bye!${C.RESET}`);
            rl.close();
            break;
        }

        messages.push({ role: 'user', content: user });

        // Agentic loop — keep going until no more tool calls
        while (true) {
            let r;
            try {
                r = await request(`${ollamaBase}/api/chat`, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({
                        model,
                        messages,
                        tools:   TOOLS,
                        stream:  false,
                        options: { temperature: 0.3 },
                    }),
                });
                if (!r.ok) throw new Error(`Ollama HTTP ${r.status}: ${r.text().slice(0, 200)}`);
            } catch (e) {
                if (e.code === 'ECONNREFUSED') {
                    console.log(`${C.RED}Cannot reach Ollama at ${ollamaBase}${C.RESET}`);
                    console.log(`${C.GRAY}Run: ollama serve${C.RESET}`);
                } else {
                    console.log(`${C.RED}Ollama error: ${e.message}${C.RESET}`);
                }
                break;
            }

            const resp = r.json().message ?? {};

            if (resp.tool_calls && resp.tool_calls.length > 0) {
                messages.push({
                    role:       'assistant',
                    content:    resp.content ?? '',
                    tool_calls: resp.tool_calls,
                });
                for (const tc of resp.tool_calls) {
                    const fnName = tc.function.name;
                    let   fnArgs = tc.function.arguments ?? {};
                    if (typeof fnArgs === 'string') {
                        try { fnArgs = JSON.parse(fnArgs); } catch { fnArgs = {}; }
                    }
                    const result = await runTool(fnName, fnArgs);
                    messages.push({ role: 'tool', content: result });
                }
                // Loop back to get the model's response to tool results

            } else {
                // Final text response
                const reply = (resp.content ?? '').trim();
                if (reply) console.log(`\n${C.GREEN}Assistant:${C.RESET} ${reply}\n`);
                messages.push({ role: 'assistant', content: reply });
                break;
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Main
// ─────────────────────────────────────────────────────────────────────────────

async function main() {
    const argv = process.argv.slice(2);
    let model      = DEFAULT_MODEL;
    let ollamaHost = OLLAMA_BASE;

    for (let i = 0; i < argv.length; i++) {
        if (argv[i] === '--model'       && argv[i + 1]) { model      = argv[++i]; }
        if (argv[i] === '--ollama-host' && argv[i + 1]) { ollamaHost = argv[++i]; }
        if (argv[i] === '--magento'     && argv[i + 1]) { MAGENTO_BASE = argv[++i]; }
    }

    if (UCP_TOKEN_SECRET === 'your-token-secret-here') {
        console.error(`${C.RED}Set UCP_TOKEN_SECRET in ucp_chat.js${C.RESET}`);
        process.exit(1);
    }

    // Check Ollama is up
    try {
        const r = await request(`${ollamaHost}/api/tags`);
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        const models = (r.json().models ?? []).map(m => m.name);
        if (!models.some(m => m.includes(model))) {
            console.error(`${C.RED}Model '${model}' not pulled.${C.RESET}`);
            console.error(`${C.GRAY}Run: ollama pull ${model}${C.RESET}`);
            process.exit(1);
        }
    } catch (e) {
        if (e.code === 'ECONNREFUSED' || e.message.includes('ECONNREFUSED')) {
            console.error(`${C.RED}Cannot reach Ollama at ${ollamaHost}${C.RESET}`);
            console.error(`${C.GRAY}Run: ollama serve${C.RESET}`);
        } else {
            console.error(`${C.RED}Ollama check failed: ${e.message}${C.RESET}`);
        }
        process.exit(1);
    }

    await chat(model, ollamaHost);
}

main().catch(e => {
    console.error(`${C.RED}Fatal: ${e.message}${C.RESET}`);
    process.exit(1);
});
