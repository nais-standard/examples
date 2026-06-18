# WeatherAgent — NAIS Reference Implementation

WeatherAgent is the canonical demo agent for the NAIS (Network Agent Identity Standard). It demonstrates end-to-end agent discovery, resolution, and interaction using DNS and HTTPS.

**Live domain:** `weatheragent.nais.id`

## How NAIS Discovery Works

NAIS uses a two-step discovery model built entirely on existing internet infrastructure:

```
Domain: weatheragent.nais.id
  ↓
Step 1: DNS TXT lookup at _agent.weatheragent.nais.id
  ↓
Step 2: Fetch manifest from /.well-known/agent.json
  ↓
Step 3: Call the MCP endpoint at /mcp
```

No proprietary registries. No centralized directories. Just DNS and HTTPS.

## DNS Records

### Required: Agent Discovery

```dns
_agent.weatheragent.nais.id  IN  TXT  "v=nais1; manifest=https://weatheragent.nais.id/.well-known/agent.json; k=ed25519:oc5a92N1h1Vg9PlnM8CrB0MAw3mMddFhZTrVuMkzceQ"
```

| Field | Value | Meaning |
|-------|-------|---------|
| `v` | `nais1` | NAIS protocol version 1 |
| `manifest` | `https://weatheragent.nais.id/.well-known/agent.json` | URL to the agent's identity card |
| `k` | `ed25519:oc5a92N1h1Vg9PlnM8CrB0MAw3mMddFhZTrVuMkzceQ` | Signing-key fingerprint; must equal the card's `signature.kid` |

The `mcp`, `auth`, and `pay` shortcut fields no longer live in DNS — those details are carried in the signed card.

### Optional: Wallet Identity

```dns
_wallet.weatheragent.nais.id  IN  TXT  "eth=0x742d35Cc6634C0532925a3b8D4C9B7F1A2e3d4E5; chains=ethereum,base,polygon"
```

Links a wallet address to the agent's domain identity. Used for wallet-authenticated access and payment verification.

### Optional: Payment Configuration

```dns
_payments.weatheragent.nais.id  IN  TXT  "method=x402; currency=USDC; chain=base; address=0x742d35Cc6634C0532925a3b8D4C9B7F1A2e3d4E5"
```

Declares payment preferences directly in DNS. Agents or clients can read this before initiating a paid request.

## Agent Card

The signed card at `/.well-known/agent.json` describes everything about the agent in a flat shape:

- **Identity:** `nais`, `cardVersion`, `updated`, `name`, `domain`, `description`
- **Tags:** `tags` — free-form discovery hints (e.g. `forecast`, `current_weather`, `alerts`)
- **Service:** `mcp` endpoint URL
- **Auth:** `auth` array of `{ "scheme": ... }` entries
- **Payment:** `payment` with `type:"x402"`, `networks`, `assets`, `payTo`, and `pricing`
- **MCP snapshot:** `mcpSnapshot` — an advisory cache of the tool list; the live MCP `tools/list` is authoritative
- **Signature:** mandatory detached EdDSA JWS (`signature.alg`, `signature.kid`, `signature.jws`)

Fetch it:

```bash
curl https://weatheragent.nais.id/.well-known/agent.json
```

## MCP Endpoint

The agent exposes a JSON-RPC 2.0 endpoint at `/mcp` supporting the MCP protocol.

### Supported Methods

| JSON-RPC Method | Description |
|----------------|-------------|
| `initialize` | Protocol handshake, returns server info and capabilities |
| `tools/list` | List available tools with input schemas |
| `tools/call` | Execute a tool by name with arguments |

### Available Tools

| Tool | Description | Auth Required |
|------|-------------|---------------|
| `current_weather` | Real-time conditions for any location | No |
| `forecast` | Multi-day forecast (1-10 days) | No |
| `alerts` | Severe weather alerts for a region | No |

### Example: Get Current Weather

**Request:**

```bash
curl -X POST https://weatheragent.nais.id/mcp \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "method": "tools/call",
    "id": 1,
    "params": {
      "name": "current_weather",
      "arguments": {
        "location": "Miami",
        "units": "metric"
      }
    }
  }'
```

**Response:**

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "{\"location\":{\"display_name\":\"Miami\",\"city\":\"Miami\",\"type\":\"city\"},\"units\":\"metric\",\"observed_at\":\"2026-03-17T12:00:00Z\",\"condition\":\"Partly Cloudy\",\"temperature\":27.3,\"feels_like\":28.1,\"humidity\":72,\"pressure_hpa\":1014,\"visibility_km\":18.5,\"wind_speed\":14,\"wind_direction\":\"SE\",\"uv_index\":6,\"cloud_cover_pct\":16}"
      }
    ],
    "isError": false
  }
}
```

### Example: Get 3-Day Forecast

**Request:**

```bash
curl -X POST https://weatheragent.nais.id/mcp \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "method": "tools/call",
    "id": 2,
    "params": {
      "name": "forecast",
      "arguments": {
        "location": "Miami",
        "days": 3
      }
    }
  }'
```

**Response** (content.text parsed for readability):

```json
{
  "location": {
    "display_name": "Miami",
    "city": "Miami",
    "type": "city"
  },
  "units": "metric",
  "forecast_days": 3,
  "forecast": [
    {
      "date": "2026-03-17",
      "condition": "Partly Cloudy",
      "temp_high": 29.0,
      "temp_low": 21.0,
      "precip_prob": 15,
      "wind_speed": 12,
      "humidity": 68,
      "uv_index": 7,
      "sunrise": "06:32",
      "sunset": "19:28"
    },
    {
      "date": "2026-03-18",
      "condition": "Clear",
      "temp_high": 30.0,
      "temp_low": 22.0,
      "precip_prob": 10,
      "wind_speed": 9,
      "humidity": 62,
      "uv_index": 8,
      "sunrise": "06:31",
      "sunset": "19:29"
    },
    {
      "date": "2026-03-19",
      "condition": "Light Rain",
      "temp_high": 27.0,
      "temp_low": 20.0,
      "precip_prob": 65,
      "precip_mm": 7.5,
      "wind_speed": 18,
      "humidity": 78,
      "uv_index": 4,
      "sunrise": "06:30",
      "sunset": "19:30"
    }
  ]
}
```

## Resolver

The NAIS public resolver automates the full discovery flow. It performs the DNS lookup, fetches the manifest, and validates everything in one call.

```bash
curl "https://resolver.nais.id/resolve.php?domain=weatheragent.nais.id"
```

The resolver returns:

- DNS records found
- Parsed NAIS fields (version, manifest URL, signing key, MCP endpoint, auth, payments, payTo, tags)
- Card fetch status and full data
- Card signature verification result (against the DNS `k=` key)
- Schema validation results (errors and warnings)

## Directory Structure

```
weatheragent/
├── .well-known/
│   └── agent.json           # Signed NAIS agent card
├── .htaccess                # Apache routing and security headers
├── mcp.php                  # JSON-RPC 2.0 MCP endpoint (PHP 8+)
├── tools/
│   ├── nais-sign.php        # Builds + signs agent.json (reference signer)
│   ├── signing-key.demo.json # DEMO-ONLY Ed25519 keypair (see note below)
│   └── test-vector.json     # Canonical signing test vector for the SDKs
└── README.md                # This file
```

### Signing key

`tools/signing-key.demo.json` is intentionally committed so anyone can
regenerate and verify this demo card (`php tools/nais-sign.php`). **It is a
throwaway demo key — never reuse it for a real agent.** A production agent keeps
its private key offline and publishes only the public fingerprint in the
`_agent` DNS `k=` record. The `.gitignore` here blocks any other key material so
a real key can't be committed by accident.

## Testing Guide

### Step 1 — Resolve the Agent

Use the public resolver to discover the agent from its domain:

```bash
curl -s "https://resolver.nais.id/resolve.php?domain=weatheragent.nais.id" | jq .
```

Verify that `ok` is `true` and the manifest was fetched successfully.

### Step 2 — Inspect the Card

Fetch the card directly to see the agent's full identity:

```bash
curl -s https://weatheragent.nais.id/.well-known/agent.json | jq .
```

Check that the card includes `tags`, auth methods, payment configuration, and a `signature` whose `kid` matches the DNS `k=` value.

### Step 3 — Call the MCP Endpoint

Initialize the connection:

```bash
curl -s -X POST https://weatheragent.nais.id/mcp \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"initialize","id":1,"params":{}}' | jq .
```

List available tools:

```bash
curl -s -X POST https://weatheragent.nais.id/mcp \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":2}' | jq .
```

Call a tool:

```bash
curl -s -X POST https://weatheragent.nais.id/mcp \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/call","id":3,"params":{"name":"current_weather","arguments":{"location":"Tokyo"}}}' | jq .
```

### Step 4 — Validate on nais.id

Visit the online validator and enter `weatheragent.nais.id`:

https://nais.id/validate

The validator will check DNS discovery, manifest fetch, schema validation, and endpoint detection.

## Requirements

- PHP 8.0+
- Apache with mod_rewrite
- HTTPS (required by NAIS spec)

## What This Proves

- A domain can serve as a globally discoverable agent identity
- DNS TXT records link domains to signed agent cards and publish the signing key (`k=`)
- The `/.well-known/agent.json` card describes tags, auth, and payment in a standard format, sealed by a mandatory Ed25519 signature
- A web-server compromise alone cannot forge a card or swap the `payTo` address — an attacker needs both the DNS zone and the private signing key
- MCP endpoints are discoverable and callable through the standard
- The entire flow works with existing DNS and HTTPS infrastructure — no proprietary registries needed
