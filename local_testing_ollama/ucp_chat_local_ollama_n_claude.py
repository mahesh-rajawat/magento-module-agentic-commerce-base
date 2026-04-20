#!/usr/bin/env python3
"""
UCP Terminal Chat — supports Claude and Ollama

Usage:
  pip install requests anthropic
  python ucp_chat.py --brain claude
  python ucp_chat.py --brain ollama --model qwen2.5
"""
import argparse, json, time, hmac, hashlib, base64, sys, os, requests, urllib3

MAGENTO_BASE      = "https://default.freshm2.test"
UCP_TOKEN_SECRET  = "your-token-secret-here"
TEST_DID          = "did:web:default.freshm2.test:agents:test"
ANTHROPIC_API_KEY = "sk-ant-..."
CLAUDE_MODEL      = "claude-sonnet-4-5"
OLLAMA_BASE       = "http://localhost:11434"
DEFAULT_MODEL     = "llama3.2:latest"

def resolve_ssl():
    ev = os.environ.get("REQUESTS_CA_BUNDLE")
    wc = os.path.expanduser("~/.warden/ssl/rootca/certs/ca.cert.pem")
    if ev and os.path.exists(ev): return ev
    if os.path.exists(wc): return wc
    urllib3.disable_warnings(); return False

SSL = resolve_ssl()

class C:
    R="\033[0m"; B="\033[1m"; G="\033[92m"; Y="\033[93m"
    C="\033[96m"; P="\033[95m"; GR="\033[90m"; RE="\033[91m"

def b64u(d): return base64.urlsafe_b64encode(d).rstrip(b"=").decode()

def make_jwt(did, secret, ttl=3600):
    n=int(time.time())
    h=b64u(json.dumps({"alg":"HS256","typ":"JWT"}).encode())
    p=b64u(json.dumps({"iss":did,"aud":MAGENTO_BASE,"iat":n,"exp":n+ttl}).encode())
    s=b64u(hmac.new(secret.encode(),f"{h}.{p}".encode(),hashlib.sha256).digest())
    return f"{h}.{p}.{s}"

_tc={"t":None,"e":0}
def get_token():
    if _tc["t"] and time.time()<_tc["e"]-60: return _tc["t"]
    r=requests.post(f"{MAGENTO_BASE}/rest/V1/ucp/auth",verify=SSL,timeout=10,
        json={"request":{"did":TEST_DID,"signed_jwt":make_jwt(TEST_DID,UCP_TOKEN_SECRET),
            "requested_capabilities":["catalog.browse","catalog.search","cart.manage",
                                      "order.place","order.track","inventory.query"]}})
    if r.status_code!=200: print(f"{C.RE}Auth failed: {r.text[:200]}{C.R}"); sys.exit(1)
    d=r.json(); _tc["t"]=d["access_token"]; _tc["e"]=time.time()+d.get("expires_in",3600)
    return _tc["t"]

def hdrs(confirm=False):
    h={"Authorization":f"Bearer {get_token()}","Content-Type":"application/json"}
    if confirm: h["X-UCP-Human-Confirmation"]=b64u(f"confirm-{int(time.time())}".encode())
    return h

def _get(p):
    r=requests.get(f"{MAGENTO_BASE}/rest{p}",headers=hdrs(),verify=SSL,timeout=10)
    try: return r.json()
    except: return {"error":r.text,"status":r.status_code}

def _post(p,b,confirm=False):
    r=requests.post(f"{MAGENTO_BASE}/rest{p}",json=b,headers=hdrs(confirm),verify=SSL,timeout=10)
    try: return r.json()
    except: return {"error":r.text,"status":r.status_code}

CLAUDE_TOOLS = [
    {"name":"discover_store","description":"Discover store UCP capabilities and auth endpoint.",
     "input_schema":{"type":"object","properties":{}}},
    {"name":"browse_catalog","description":"Browse products. Returns name, SKU, prices, stock.",
     "input_schema":{"type":"object","properties":{"page_size":{"type":"integer"}}}},
    {"name":"search_catalog","description":"Search products by keyword.",
     "input_schema":{"type":"object","properties":{"query":{"type":"string"}},"required":["query"]}},
    {"name":"check_inventory","description":"Check stock for a SKU.",
     "input_schema":{"type":"object","properties":{"sku":{"type":"string"}},"required":["sku"]}},
    {"name":"view_cart","description":"View cart contents and totals.",
     "input_schema":{"type":"object","properties":{}}},
    {"name":"add_to_cart","description":"Add product to cart by SKU.",
     "input_schema":{"type":"object","properties":{"sku":{"type":"string"},"qty":{"type":"integer"}},"required":["sku"]}},
    {"name":"remove_from_cart","description":"Remove item from cart by item ID.",
     "input_schema":{"type":"object","properties":{"item_id":{"type":"integer"}},"required":["item_id"]}},
    {"name":"get_shipping_methods","description":"Get available shipping methods.",
     "input_schema":{"type":"object","properties":{}}},
    {"name":"set_shipping",
     "description":"Set shipping address and method. Pass billing_same_as_shipping=true (default) unless customer wants different billing.",
     "input_schema":{"type":"object",
       "properties":{"firstname":{"type":"string"},"lastname":{"type":"string"},
         "street":{"type":"string"},"city":{"type":"string"},
         "region_code":{"type":"string"},"postcode":{"type":"string"},
         "country_id":{"type":"string"},"telephone":{"type":"string"},
         "shipping_method_code":{"type":"string"},
         "billing_same_as_shipping":{"type":"boolean"}},
       "required":["firstname","lastname","street","city","region_code","postcode",
                   "country_id","telephone","shipping_method_code"]}},
    {"name":"set_billing","description":"Set separate billing address only when explicitly different from shipping.",
     "input_schema":{"type":"object",
       "properties":{"firstname":{"type":"string"},"lastname":{"type":"string"},
         "street":{"type":"string"},"city":{"type":"string"},
         "region_code":{"type":"string"},"postcode":{"type":"string"},
         "country_id":{"type":"string"},"telephone":{"type":"string"}},
       "required":["firstname","lastname","street","city","region_code","postcode","country_id","telephone"]}},
    {"name":"get_totals","description":"Get cart totals including grand total.",
     "input_schema":{"type":"object","properties":{}}},
    {"name":"get_payment_methods","description":"Get available payment methods.",
     "input_schema":{"type":"object","properties":{}}},
    {"name":"place_order","description":"Place the order. Only call when customer confirms.",
     "input_schema":{"type":"object",
       "properties":{"payment_method_code":{"type":"string"},"email":{"type":"string"}},
       "required":["payment_method_code","email"]}},
    {"name":"track_order","description":"Track order by increment ID.",
     "input_schema":{"type":"object","properties":{"order_id":{"type":"string"}},"required":["order_id"]}},
]

OLLAMA_TOOLS = [{"type":"function","function":{"name":t["name"],"description":t["description"],
    "parameters":t.get("input_schema",{"type":"object","properties":{}})}} for t in CLAUDE_TOOLS]

def run_tool(name, args):
    print(f"{C.GR}  [tool: {name}({json.dumps(args)})]{C.R}")
    try:
        if   name=="discover_store":     return json.dumps(requests.get(f"{MAGENTO_BASE}/.well-known/ucp.json",verify=SSL,timeout=10).json(),indent=2)
        elif name=="browse_catalog":     return json.dumps(_get(f"/V1/ucp/catalog?pageSize={args.get('page_size',10)}"),indent=2)
        elif name=="search_catalog":     return json.dumps(_get(f"/V1/ucp/search?q={args['query']}"),indent=2)
        elif name=="check_inventory":    return json.dumps(_get(f"/V1/ucp/inventory?sku={args['sku']}"),indent=2)
        elif name=="view_cart":          return json.dumps(_get("/V1/ucp/cart"),indent=2)
        elif name=="add_to_cart":        return json.dumps(_post("/V1/ucp/cart",{"sku":args["sku"],"qty":args.get("qty",1)}),indent=2)
        elif name=="remove_from_cart":
            r=requests.delete(f"{MAGENTO_BASE}/rest/V1/ucp/cart/{args['item_id']}",headers=hdrs(),verify=SSL,timeout=10)
            try: return json.dumps(r.json(),indent=2)
            except: return json.dumps({"status":r.status_code})
        elif name=="get_shipping_methods": return json.dumps(_get("/V1/ucp/checkout/shipping-methods"),indent=2)
        elif name=="set_shipping":       return json.dumps(_post("/V1/ucp/checkout/shipping",args),indent=2)
        elif name=="set_billing":        return json.dumps(_post("/V1/ucp/checkout/billing",args),indent=2)
        elif name=="get_totals":         return json.dumps(_get("/V1/ucp/checkout/totals"),indent=2)
        elif name=="get_payment_methods":return json.dumps(_get("/V1/ucp/checkout/payment-methods"),indent=2)
        elif name=="place_order":        return json.dumps(_post("/V1/ucp/order",args,confirm=True),indent=2)
        elif name=="track_order":        return json.dumps(_get(f"/V1/ucp/order/{args['order_id']}"),indent=2)
        else: return json.dumps({"error":f"Unknown tool: {name}"})
    except requests.exceptions.SSLError:
        return json.dumps({"error":"SSL error — export REQUESTS_CA_BUNDLE=~/.warden/ssl/rootca/certs/ca.cert.pem"})
    except requests.exceptions.ConnectionError:
        return json.dumps({"error":f"Cannot connect to {MAGENTO_BASE}"})
    except Exception as e:
        return json.dumps({"error":str(e)})

SYSTEM = """You are a helpful AI shopping assistant for a Magento store.
You have tools to browse products, manage a cart, and place orders.
- Be conversational and friendly
- NEVER invent product names, prices or SKUs — always call browse_catalog or search_catalog first
- Use price_summary field to answer price questions accurately
- Show cart total before asking about placing an order
- Always confirm with customer before calling place_order
- When calling set_shipping, ALWAYS pass billing_same_as_shipping=true unless customer says otherwise
- Never ask for billing address separately — assume same as shipping by default"""

def header(brain, model_str):
    print(f"""
{C.B}{C.P}UCP Magento Chat — {brain}{C.R}
{C.GR}Model: {model_str}  |  Store: {MAGENTO_BASE}{C.R}
{C.GR}─────────────────────────────────────────────{C.R}
{C.GR}Try: "show me products"  |  "add X to cart"  |  "what's my total"{C.R}
{C.GR}Type 'quit' to exit{C.R}
""")
    print(f"{C.GR}Authenticating with Magento...{C.R}",end=" ",flush=True)
    try:
        get_token(); print(f"{C.G}ready{C.R}\n")
    except Exception as e:
        print(f"{C.RE}failed: {e}{C.R}"); sys.exit(1)

def chat_claude():
    try: import anthropic
    except ImportError: print(f"{C.RE}pip install anthropic{C.R}"); sys.exit(1)
    if ANTHROPIC_API_KEY.startswith("sk-ant-..."):
        print(f"{C.RE}Set ANTHROPIC_API_KEY in ucp_chat.py{C.R}")
        print(f"{C.GR}Get key at: https://console.anthropic.com{C.R}"); sys.exit(1)

    client=anthropic.Anthropic(api_key=ANTHROPIC_API_KEY)
    messages=[]
    header("Claude", CLAUDE_MODEL)

    while True:
        try: user=input(f"{C.C}You:{C.R} ").strip()
        except (KeyboardInterrupt,EOFError): print(f"\n{C.GR}Bye!{C.R}"); break
        if not user: continue
        if user.lower() in ("quit","exit","q"): print(f"{C.GR}Bye!{C.R}"); break

        messages.append({"role":"user","content":user})

        while True:
            try:
                resp=client.messages.create(model=CLAUDE_MODEL,max_tokens=1024,
                    system=SYSTEM,tools=CLAUDE_TOOLS,messages=messages)
            except Exception as e:
                print(f"{C.RE}Claude error: {e}{C.R}"); break

            tool_calls=[b for b in resp.content if b.type=="tool_use"]
            texts=[b.text for b in resp.content if b.type=="text" and b.text]

            if not tool_calls:
                reply=" ".join(texts).strip()
                if reply: print(f"\n{C.G}Claude:{C.R} {reply}\n")
                messages.append({"role":"assistant","content":resp.content})
                break

            messages.append({"role":"assistant","content":resp.content})
            results=[]
            for tc in tool_calls:
                results.append({"type":"tool_result","tool_use_id":tc.id,
                                 "content":run_tool(tc.name,tc.input)})
            messages.append({"role":"user","content":results})

def chat_ollama(model, base):
    messages=[{"role":"system","content":SYSTEM}]
    header("Ollama", model)

    while True:
        try: user=input(f"{C.C}You:{C.R} ").strip()
        except (KeyboardInterrupt,EOFError): print(f"\n{C.GR}Bye!{C.R}"); break
        if not user: continue
        if user.lower() in ("quit","exit","q"): print(f"{C.GR}Bye!{C.R}"); break

        messages.append({"role":"user","content":user})

        while True:
            try:
                r=requests.post(f"{base}/api/chat",timeout=120,
                    json={"model":model,"messages":messages,"tools":OLLAMA_TOOLS,
                          "stream":False,"options":{"temperature":0}})
                r.raise_for_status()
            except requests.exceptions.ConnectionError:
                print(f"{C.RE}Cannot reach Ollama at {base}{C.R}")
                print(f"{C.GR}Run: ollama serve{C.R}"); break
            except Exception as e:
                print(f"{C.RE}Ollama error: {e}{C.R}"); break

            msg=r.json().get("message",{})
            if msg.get("tool_calls"):
                messages.append({"role":"assistant","content":msg.get("content",""),
                                  "tool_calls":msg["tool_calls"]})
                for tc in msg["tool_calls"]:
                    fn=tc["function"]["name"]
                    fa=tc["function"].get("arguments",{})
                    if isinstance(fa,str):
                        try: fa=json.loads(fa)
                        except: fa={}
                    messages.append({"role":"tool","content":run_tool(fn,fa)})
            else:
                reply=msg.get("content","").strip()
                if reply: print(f"\n{C.G}Assistant:{C.R} {reply}\n")
                messages.append({"role":"assistant","content":reply})
                break

def main():
    ap=argparse.ArgumentParser(description="UCP Magento terminal chat",
        epilog="Examples:\n  python ucp_chat.py --brain claude\n  python ucp_chat.py --brain ollama --model qwen2.5",
        formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--brain",choices=["claude","ollama"],default="ollama",
                    help="AI brain: claude or ollama (default: ollama)")
    ap.add_argument("--model",default=DEFAULT_MODEL,help="Ollama model name")
    ap.add_argument("--ollama-host",default=OLLAMA_BASE,help="Ollama URL")
    ap.add_argument("--magento",default=None,help="Override Magento URL")
    a=ap.parse_args()

    global MAGENTO_BASE
    if a.magento: MAGENTO_BASE=a.magento
    if UCP_TOKEN_SECRET=="your-token-secret-here":
        print(f"{C.RE}Set UCP_TOKEN_SECRET in ucp_chat.py{C.R}"); sys.exit(1)

    if a.brain=="claude":
        chat_claude()
    else:
        try:
            r=requests.get(f"{a.ollama_host}/api/tags",timeout=3)
            ms=[m["name"] for m in r.json().get("models",[])]
            if not any(a.model in m for m in ms):
                print(f"{C.RE}Model '{a.model}' not pulled. Run: ollama pull {a.model}{C.R}"); sys.exit(1)
        except:
            print(f"{C.RE}Cannot reach Ollama at {a.ollama_host}. Run: ollama serve{C.R}"); sys.exit(1)
        chat_ollama(a.model, a.ollama_host)

if __name__=="__main__":
    main()