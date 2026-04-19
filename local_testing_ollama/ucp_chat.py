#!/usr/bin/env python3
"""
UCP Terminal Chat
==================
Conversational chat with Ollama that calls your Magento UCP
endpoints as tools in real time.

Usage:
  pip install requests
  python ucp_chat.py
  python ucp_chat.py --model qwen2.5
  python ucp_chat.py --model llama3.1 --ollama-host http://host.docker.internal:11434

Type naturally — the model decides which tools to call.
Type 'quit' or Ctrl+C to exit.
"""

import argparse
import json
import time
import hmac
import hashlib
import base64
import sys
import os
import requests
import urllib3

# ─────────────────────────────────────────────────────────────────────────────
# CONFIG — edit these
# ─────────────────────────────────────────────────────────────────────────────

MAGENTO_BASE     = "https://default.freshm2.test"
UCP_TOKEN_SECRET = "7d5f7cfffa2e773a1344ec4d119f380787f14b775e1bb5524278f24e54c7a102"
TEST_DID         = "did:web:default.freshm2.test:agents:test"
OLLAMA_BASE      = "http://localhost:11434"
DEFAULT_MODEL    = "qwen2.5"

# ─────────────────────────────────────────────────────────────────────────────
# SSL — Warden CA auto-detection
# ─────────────────────────────────────────────────────────────────────────────

def resolve_ssl():
    env_ca    = os.environ.get("REQUESTS_CA_BUNDLE")
    warden_ca = os.path.expanduser("~/.warden/ssl/rootca/certs/ca.cert.pem")
    if env_ca and os.path.exists(env_ca):
        return env_ca
    if os.path.exists(warden_ca):
        return warden_ca
    urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
    return False

SSL = resolve_ssl()

# ─────────────────────────────────────────────────────────────────────────────
# Terminal colours
# ─────────────────────────────────────────────────────────────────────────────

class C:
    RESET  = "\033[0m";  BOLD   = "\033[1m"
    GREEN  = "\033[92m"; YELLOW = "\033[93m"
    CYAN   = "\033[96m"; PURPLE = "\033[95m"
    GRAY   = "\033[90m"; RED    = "\033[91m"

# ─────────────────────────────────────────────────────────────────────────────
# JWT + auth helpers
# ─────────────────────────────────────────────────────────────────────────────

def b64u(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode()

def make_jwt(did: str, secret: str, ttl: int = 3600) -> str:
    now = int(time.time())
    h   = b64u(json.dumps({"alg": "HS256", "typ": "JWT"}).encode())
    p   = b64u(json.dumps({
        "iss": did,
        "aud": MAGENTO_BASE,
        "iat": now,
        "exp": now + ttl,
    }).encode())
    sig = b64u(hmac.new(secret.encode(), f"{h}.{p}".encode(),
                        hashlib.sha256).digest())
    return f"{h}.{p}.{sig}"

# Token cache — re-auth automatically when expired
_token_cache = {"token": None, "exp": 0}

def get_token() -> str:
    if _token_cache["token"] and time.time() < _token_cache["exp"] - 60:
        return _token_cache["token"]

    r = requests.post(
        f"{MAGENTO_BASE}/rest/V1/ucp/auth",
        json={"request": {
            "did":                    TEST_DID,
            "signed_jwt":             make_jwt(TEST_DID, UCP_TOKEN_SECRET),
            "requested_capabilities": [
                "catalog.browse", "catalog.search",
                "cart.manage",    "order.place",
                "order.track",    "inventory.query",
            ],
        }},
        verify=SSL, timeout=10,
    )

    if r.status_code != 200:
        print(f"{C.RED}Auth failed: {r.text[:200]}{C.RESET}")
        sys.exit(1)

    data = r.json()
    _token_cache["token"] = data["access_token"]
    _token_cache["exp"]   = time.time() + data.get("expires_in", 3600)
    return _token_cache["token"]

def _headers(confirm: bool = False) -> dict:
    h = {
        "Authorization": f"Bearer {get_token()}",
        "Content-Type":  "application/json",
    }
    if confirm:
        h["X-UCP-Human-Confirmation"] = b64u(
            f"confirm-{int(time.time())}".encode()
        )
    return h

def _get(path: str) -> dict:
    r = requests.get(
        f"{MAGENTO_BASE}/rest{path}",
        headers=_headers(), verify=SSL, timeout=10,
    )
    try:
        return r.json()
    except Exception:
        return {"error": r.text, "status": r.status_code}

def _post(path: str, body: dict, confirm: bool = False) -> dict:
    r = requests.post(
        f"{MAGENTO_BASE}/rest{path}",
        json=body, headers=_headers(confirm),
        verify=SSL, timeout=10,
    )
    try:
        return r.json()
    except Exception:
        return {"error": r.text, "status": r.status_code}

# ─────────────────────────────────────────────────────────────────────────────
# Tool definitions — Ollama OpenAI-style function calling format
# ─────────────────────────────────────────────────────────────────────────────

TOOLS = [
    {
        "type": "function",
        "function": {
            "name": "discover_store",
            "description": "Discover what the store supports — capabilities, agents, auth endpoint. Call this first.",
            "parameters": {"type": "object", "properties": {}},
        },
    },
    {
        "type": "function",
        "function": {
            "name": "browse_catalog",
            "description": "Browse available products. Returns name, SKU, price, stock status.",
            "parameters": {
                "type": "object",
                "properties": {
                    "page_size": {"type": "integer", "description": "Products per page (default 10)"},
                },
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "search_catalog",
            "description": "Search products by keyword. Use this to find specific items.",
            "parameters": {
                "type": "object",
                "properties": {
                    "query": {"type": "string", "description": "Search keyword e.g. shirt, laptop"},
                },
                "required": ["query"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "check_inventory",
            "description": "Check if a specific SKU is in stock and how many are available.",
            "parameters": {
                "type": "object",
                "properties": {
                    "sku": {"type": "string", "description": "Product SKU to check"},
                },
                "required": ["sku"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "view_cart",
            "description": "View the current cart contents, item list, and totals.",
            "parameters": {"type": "object", "properties": {}},
        },
    },
    {
        "type": "function",
        "function": {
            "name": "add_to_cart",
            "description": "Add a product to the cart by SKU and quantity.",
            "parameters": {
                "type": "object",
                "properties": {
                    "sku": {"type": "string",  "description": "Product SKU"},
                    "qty": {"type": "integer", "description": "Quantity to add (default 1)"},
                },
                "required": ["sku"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "remove_from_cart",
            "description": "Remove a specific item from the cart by its cart item ID.",
            "parameters": {
                "type": "object",
                "properties": {
                    "item_id": {"type": "integer", "description": "Cart item ID from view_cart response"},
                },
                "required": ["item_id"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_shipping_methods",
            "description": "Get available shipping methods and their costs for the current cart.",
            "parameters": {"type": "object", "properties": {}},
        },
    },
    {
        "type": "function",
        "function": {
            "name": "set_shipping",
            "description": "Set the shipping address and delivery method. Required before placing an order.",
            "parameters": {
                "type": "object",
                "properties": {
                    "firstname":            {"type": "string"},
                    "lastname":             {"type": "string"},
                    "street":               {"type": "string"},
                    "city":                 {"type": "string"},
                    "region_code":          {"type": "string", "description": "Ask the customer for their state/region"},
                    "postcode":             {"type": "string"},
                    "country_id":           {"type": "string", "description": "2-letter country e.g. US"},
                    "telephone":            {"type": "string"},
                    "shipping_method_code": {"type": "string", "description": "e.g. flatrate_flatrate"},
                },
                "required": ["firstname", "lastname", "street", "city",
                             "region_code", "postcode", "country_id",
                             "telephone", "shipping_method_code"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "set_shipping",
            "description": (
                "Set the shipping address, delivery method, and optionally billing address. "
                "Pass billing_same_as_shipping=true (default) to copy shipping to billing automatically — "
                "only set it to false if the customer explicitly wants a different billing address."
            ),
            "parameters": {
                "type": "object",
                "properties": {
                    "firstname":                 {"type": "string"},
                    "lastname":                  {"type": "string"},
                    "street":                    {"type": "string"},
                    "city":                      {"type": "string"},
                    "region_code":               {"type": "string", "description": "Ask the customer for their state/region"},
                    "postcode":                  {"type": "string"},
                    "country_id":                {"type": "string", "description": "2-letter country e.g. US"},
                    "telephone":                 {"type": "string"},
                    "shipping_method_code":      {"type": "string", "description": "e.g. flatrate_flatrate"},
                    "billing_same_as_shipping":  {"type": "boolean", "description": "Default true — set false only if customer wants different billing address"},
                },
                "required": ["firstname", "lastname", "street", "city",
                             "region_code", "postcode", "country_id",
                             "telephone", "shipping_method_code"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_totals",
            "description": "Get the cart totals including subtotal, shipping, tax, and grand total.",
            "parameters": {"type": "object", "properties": {}},
        },
    },
    {
        "type": "function",
        "function": {
            "name": "get_payment_methods",
            "description": "Get available payment methods for the current cart.",
            "parameters": {"type": "object", "properties": {}},
        },
    },
    {
        "type": "function",
        "function": {
            "name": "place_order",
            "description": "Place the order. Only call this when the customer explicitly confirms they want to buy.",
            "parameters": {
                "type": "object",
                "properties": {
                    "payment_method_code": {"type": "string", "description": "e.g. checkmo or free"},
                    "email":               {"type": "string", "description": "Customer email for order confirmation"},
                },
                "required": ["payment_method_code", "email"],
            },
        },
    },
    {
        "type": "function",
        "function": {
            "name": "track_order",
            "description": "Track an existing order by its increment ID to see status and shipping info.",
            "parameters": {
                "type": "object",
                "properties": {
                    "order_id": {"type": "string", "description": "Order increment ID e.g. 000000001"},
                },
                "required": ["order_id"],
            },
        },
    },
]

# ─────────────────────────────────────────────────────────────────────────────
# Tool executor
# ─────────────────────────────────────────────────────────────────────────────

def run_tool(name: str, args: dict) -> str:
    print(f"{C.GRAY}  [calling {name}({json.dumps(args)})]{C.RESET}")
    try:
        if name == "discover_store":
            r = requests.get(f"{MAGENTO_BASE}/.well-known/ucp.json",
                             verify=SSL, timeout=10)
            return json.dumps(r.json(), indent=2)

        elif name == "browse_catalog":
            ps = args.get("page_size", 10)
            return json.dumps(_get(f"/V1/ucp/catalog?pageSize={ps}"), indent=2)

        elif name == "search_catalog":
            q = args.get("query", "")
            return json.dumps(_get(f"/V1/ucp/search?q={q}"), indent=2)

        elif name == "check_inventory":
            return json.dumps(_get(f"/V1/ucp/inventory?sku={args['sku']}"), indent=2)

        elif name == "view_cart":
            return json.dumps(_get("/V1/ucp/cart"), indent=2)

        elif name == "add_to_cart":
            return json.dumps(_post("/V1/ucp/cart", {
                "sku": args["sku"],
                "qty": args.get("qty", 1),
            }), indent=2)

        elif name == "remove_from_cart":
            r = requests.delete(
                f"{MAGENTO_BASE}/rest/V1/ucp/cart/{args['item_id']}",
                headers=_headers(), verify=SSL, timeout=10,
            )
            try:
                return json.dumps(r.json(), indent=2)
            except Exception:
                return json.dumps({"status": r.status_code})

        elif name == "get_shipping_methods":
            return json.dumps(_get("/V1/ucp/checkout/shipping-methods"), indent=2)

        elif name == "set_shipping":
            return json.dumps(_post("/V1/ucp/checkout/shipping", args), indent=2)
        elif name == "set_billing":
            return json.dumps(_post("/V1/ucp/checkout/billing", {
                "firstname":   args["firstname"],
                "lastname":    args["lastname"],
                "street":      args["street"],
                "city":        args["city"],
                "region_code": args["region_code"],
                "postcode":    args["postcode"],
                "country_id":  args["country_id"],
                "telephone":   args["telephone"],
            }), indent=2)
        elif name == "get_totals":
            return json.dumps(_get("/V1/ucp/checkout/totals"), indent=2)

        elif name == "get_payment_methods":
            return json.dumps(_get("/V1/ucp/checkout/payment-methods"), indent=2)

        elif name == "place_order":
            return json.dumps(_post("/V1/ucp/order", args, confirm=True), indent=2)

        elif name == "track_order":
            return json.dumps(_get(f"/V1/ucp/order/{args['order_id']}"), indent=2)

        else:
            return json.dumps({"error": f"Unknown tool: {name}"})

    except requests.exceptions.SSLError as e:
        return json.dumps({"error": f"SSL error — try: export REQUESTS_CA_BUNDLE=~/.warden/ssl/rootca/certs/ca.cert.pem"})
    except requests.exceptions.ConnectionError as e:
        return json.dumps({"error": f"Cannot connect to Magento at {MAGENTO_BASE}"})
    except Exception as e:
        return json.dumps({"error": str(e)})

# ─────────────────────────────────────────────────────────────────────────────
# Ollama chat loop
# ─────────────────────────────────────────────────────────────────────────────

SYSTEM = """You are a helpful AI shopping assistant for a Magento store.
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
- NEVER make up or guess prices — only use what the tool returns"""


def chat(model: str, ollama_base: str):
    messages = [{"role": "system", "content": SYSTEM}]

    print(f"""
{C.BOLD}{C.PURPLE}UCP Magento Chat{C.RESET}
{C.GRAY}Model: {model}  |  Store: {MAGENTO_BASE}{C.RESET}
{C.GRAY}─────────────────────────────────────────────{C.RESET}
{C.GRAY}Try: "show me products"  |  "add X to cart"  |  "what's my total"{C.RESET}
{C.GRAY}Type 'quit' to exit{C.RESET}
""")

    # Authenticate on startup
    print(f"{C.GRAY}Authenticating...{C.RESET}", end=" ", flush=True)
    try:
        get_token()
        print(f"{C.GREEN}ready{C.RESET}\n")
    except Exception as e:
        print(f"{C.RED}failed: {e}{C.RESET}")
        sys.exit(1)

    while True:
        try:
            user = input(f"{C.CYAN}You:{C.RESET} ").strip()
        except (KeyboardInterrupt, EOFError):
            print(f"\n{C.GRAY}Bye!{C.RESET}")
            break

        if not user:
            continue
        if user.lower() in ("quit", "exit", "q"):
            print(f"{C.GRAY}Bye!{C.RESET}")
            break

        messages.append({"role": "user", "content": user})

        # Agentic loop — keep going until no more tool calls
        while True:
            try:
                r = requests.post(
                    f"{ollama_base}/api/chat",
                    json={
                        "model":    model,
                        "messages": messages,
                        "tools":    TOOLS,
                        "stream":   False,
                        "options":  {"temperature": 0.3},
                    },
                    timeout=120,
                )
                r.raise_for_status()
            except requests.exceptions.ConnectionError:
                print(f"{C.RED}Cannot reach Ollama at {ollama_base}{C.RESET}")
                print(f"{C.GRAY}Run: ollama serve{C.RESET}")
                break
            except Exception as e:
                print(f"{C.RED}Ollama error: {e}{C.RESET}")
                break

            resp = r.json().get("message", {})

            # Tool calls
            if resp.get("tool_calls"):
                messages.append({
                    "role":       "assistant",
                    "content":    resp.get("content", ""),
                    "tool_calls": resp["tool_calls"],
                })
                for tc in resp["tool_calls"]:
                    fn_name = tc["function"]["name"]
                    fn_args = tc["function"].get("arguments", {})
                    if isinstance(fn_args, str):
                        try:
                            fn_args = json.loads(fn_args)
                        except Exception:
                            fn_args = {}
                    result = run_tool(fn_name, fn_args)
                    messages.append({
                        "role":    "tool",
                        "content": result,
                    })
                # Loop back to get the model's response to tool results

            else:
                # Final text response
                reply = resp.get("content", "").strip()
                if reply:
                    print(f"\n{C.GREEN}Assistant:{C.RESET} {reply}\n")
                messages.append({"role": "assistant", "content": reply})
                break

# ─────────────────────────────────────────────────────────────────────────────
# Main
# ─────────────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description="UCP Magento terminal chat")
    parser.add_argument("--model",       default=DEFAULT_MODEL,
                        help="Ollama model name (default: llama3.2:latest)")
    parser.add_argument("--ollama-host", default=OLLAMA_BASE,
                        help="Ollama API URL (default: http://localhost:11434)")
    parser.add_argument("--magento",     default=None,
                        help="Override Magento base URL")
    args = parser.parse_args()

    global MAGENTO_BASE
    if args.magento:
        MAGENTO_BASE = args.magento

    if UCP_TOKEN_SECRET == "your-token-secret-here":
        print(f"{C.RED}Set UCP_TOKEN_SECRET in ucp_chat.py{C.RESET}")
        sys.exit(1)

    # Check Ollama is up
    try:
        r = requests.get(f"{args.ollama_host}/api/tags", timeout=3)
        models = [m["name"] for m in r.json().get("models", [])]
        if not any(args.model in m for m in models):
            print(f"{C.RED}Model '{args.model}' not pulled.{C.RESET}")
            print(f"{C.GRAY}Run: ollama pull {args.model}{C.RESET}")
            sys.exit(1)
    except Exception:
        print(f"{C.RED}Cannot reach Ollama at {args.ollama_host}{C.RESET}")
        print(f"{C.GRAY}Run: ollama serve{C.RESET}")
        sys.exit(1)

    chat(args.model, args.ollama_host)


if __name__ == "__main__":
    main()