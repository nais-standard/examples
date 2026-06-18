# NAIS Examples

Example implementations and integration patterns for the NAIS standard.

## Weather Agent Demo

The `weatheragent/` directory contains a complete, working NAIS agent deployed at `weatheragent.nais.id`. This is the reference demo for the standard.

### What's Included

```
weatheragent/
├── .well-known/
│   └── agent.json     # NAIS manifest
├── mcp.php            # JSON-RPC 2.0 MCP endpoint
└── .htaccess          # Apache routing
```

### DNS Record

```dns
_agent.weatheragent.nais.id  TXT  "v=nais1; manifest=https://weatheragent.nais.id/.well-known/agent.json; k=ed25519:oc5a92N1h1Vg9PlnM8CrB0MAw3mMddFhZTrVuMkzceQ"
```

The `k=` field is the signing-key fingerprint; it must equal the card's `signature.kid`.

### MCP Tools

The demo agent exposes 3 tools via MCP:

| Tool | Description |
|------|-------------|
| `forecast` | Multi-day weather forecast (1-10 days) |
| `current_weather` | Real-time current conditions |
| `alerts` | Severe weather alerts |

### Try It

```bash
# Resolve the agent
curl "https://resolver.nais.id/resolve.php?domain=weatheragent.nais.id"

# Fetch the manifest
curl "https://weatheragent.nais.id/.well-known/agent.json"

# Call the MCP endpoint
curl -X POST "https://weatheragent.nais.id/mcp" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":1}'
```

### Using the SDK

```python
from nais import resolve

agent = resolve("weatheragent.nais.id")
print(agent["resolved"]["mcp_endpoint"])
# https://weatheragent.nais.id/mcp
```

## What This Proves

- DNS-based agent discovery works end-to-end
- The signed agent card schema is practical and complete
- MCP endpoints are discoverable through NAIS
- The resolver correctly parses, validates, and verifies the card signature against the DNS `k=` key
- Auth and payment fields are cleanly expressed in the signed card

## Related

- [spec](https://github.com/nais-standard/spec) — Protocol specification
- [resolver](https://github.com/nais-standard/resolver) — Reference resolver
- [clients](https://github.com/nais-standard/clients) — SDKs for integration
