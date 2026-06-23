<?php
declare(strict_types=1);

/**
 * nais-sign.php — Reference NAIS card signing tool (demo)
 * ─────────────────────────────────────────────────────────────────────────────
 * Builds the WeatherAgent agent.json card and signs it with a stable demo
 * Ed25519 key, producing a detached EdDSA JWS over the canonical card body.
 *
 * This file is also the executable reference for the NAIS 1.0 signing scheme:
 *
 *   1. Canonicalize the card WITHOUT its "signature" member using the NAIS
 *      canonical JSON profile (a subset of RFC 8785 / JCS):
 *        - object keys sorted ascending by byte value (keys are ASCII)
 *        - no insignificant whitespace
 *        - "/" not escaped, non-ASCII not escaped
 *        - integers emitted as integers; the card contains no floating-point
 *      => this byte string is the JWS payload.
 *   2. Protected header = {"alg":"EdDSA","kid":"<kid>"} (canonical form).
 *   3. Signing input = BASE64URL(header) . "." . BASE64URL(payload).
 *   4. signature = Ed25519(signing input, demo secret key).
 *   5. Detached compact JWS = BASE64URL(header) . ".." . BASE64URL(signature).
 *      (the middle/payload segment is empty — detached, RFC 7515 Appendix F)
 *
 *   kid = "ed25519:" . BASE64URL(raw 32-byte public key)
 *   The same value is published in DNS as the _agent TXT "k=" field, binding
 *   the card's signing key to the domain's DNS zone.
 *
 *   toolsHash = "sha256:" . hex( SHA-256( canonical(mcpSnapshot.tools) ) )
 *
 * Usage:  php tools/nais-sign.php          # (re)generate ../.well-known/agent.json
 *         php tools/nais-sign.php --vector # print the signing test vector as JSON
 *
 * Requires: PHP 7.2+ with ext-sodium.
 * ─────────────────────────────────────────────────────────────────────────────
 */

const KEY_FILE      = __DIR__ . '/signing-key.demo.json';
const MANIFEST_FILE = __DIR__ . '/../.well-known/agent.json';

// Fixed timestamp keeps the demo card byte-stable across regenerations.
const STAMP = '2026-06-17T00:00:00Z';

// ─────────────────────────────────────────────────────────────────────────────
// Canonical JSON (NAIS profile)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Produce the NAIS canonical JSON byte string for a value.
 * Objects are represented as PHP associative arrays; lists as PHP lists.
 */
function nais_canonical($value): string
{
    return nais_canon_encode($value);
}

function nais_canon_encode($v): string
{
    if (is_array($v)) {
        // List (sequential 0..n-1 integer keys) => JSON array, order preserved.
        if ($v === [] || array_keys($v) === range(0, count($v) - 1)) {
            $parts = array_map('nais_canon_encode', $v);
            return '[' . implode(',', $parts) . ']';
        }
        // Object => sort keys ascending by byte value.
        $keys = array_keys($v);
        sort($keys, SORT_STRING);
        $parts = [];
        foreach ($keys as $k) {
            $parts[] = nais_canon_string((string)$k) . ':' . nais_canon_encode($v[$k]);
        }
        return '{' . implode(',', $parts) . '}';
    }
    if (is_string($v))  return nais_canon_string($v);
    if (is_bool($v))    return $v ? 'true' : 'false';
    if ($v === null)    return 'null';
    if (is_int($v))     return (string)$v;
    if (is_float($v)) {
        // NAIS cards MUST NOT contain floating-point numbers.
        throw new RuntimeException('Floating-point values are not permitted in a NAIS card.');
    }
    throw new RuntimeException('Unsupported value type in canonicalization.');
}

/** JSON string encoding per the canonical profile (no "/" or unicode escaping). */
function nais_canon_string(string $s): string
{
    // JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE matches the profile for
    // text without control characters; control chars (if any) still escape.
    return json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

// ─────────────────────────────────────────────────────────────────────────────
// base64url
// ─────────────────────────────────────────────────────────────────────────────

function b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

// ─────────────────────────────────────────────────────────────────────────────
// Key management — stable demo keypair
// ─────────────────────────────────────────────────────────────────────────────

/** @return array{secret:string,public:string,kid:string} raw binary keys + kid */
function load_or_create_key(): array
{
    if (is_file(KEY_FILE)) {
        $d      = json_decode((string)file_get_contents(KEY_FILE), true, 8, JSON_THROW_ON_ERROR);
        $secret = base64_decode($d['secret_key_b64'], true);
        $public = base64_decode($d['public_key_b64'], true);
        if (strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Corrupt demo signing key.');
        }
        return ['secret' => $secret, 'public' => $public, 'kid' => 'ed25519:' . b64url($public)];
    }

    $pair   = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $public = sodium_crypto_sign_publickey($pair);
    $kid    = 'ed25519:' . b64url($public);

    file_put_contents(KEY_FILE, json_encode([
        '_warning'       => 'DEMO SIGNING KEY — for the public NAIS WeatherAgent demo only. Do NOT reuse for any real agent identity.',
        'alg'            => 'EdDSA',
        'kid'            => $kid,
        'public_key_b64' => base64_encode($public),
        'secret_key_b64' => base64_encode($secret),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    return ['secret' => $secret, 'public' => $public, 'kid' => $kid];
}

// ─────────────────────────────────────────────────────────────────────────────
// MCP snapshot — derived from the live tools/list (mcp.php)
// ─────────────────────────────────────────────────────────────────────────────

/** The trimmed tool list, sorted by name ascending, exactly as mcp.php serves. */
function snapshot_tools(): array
{
    $tools = [
        [
            'name'        => 'alerts',
            'description' => 'Get active severe weather alerts for a region. Returns empty array if no active alerts.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'location' => ['type' => 'string', 'description' => 'City name, region, state/country code, or "lat,lon"'],
                    'severity' => ['type' => 'string', 'enum' => ['all', 'extreme', 'severe', 'moderate', 'minor'], 'default' => 'all'],
                ],
                'required' => ['location'],
            ],
        ],
        [
            'name'        => 'current_weather',
            'description' => 'Get real-time current weather conditions for any location.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'location' => ['type' => 'string', 'description' => 'City name, "City, Country", or "lat,lon"'],
                    'units'    => ['type' => 'string', 'enum' => ['metric', 'imperial'], 'default' => 'metric'],
                ],
                'required' => ['location'],
            ],
        ],
        [
            'name'        => 'forecast',
            'description' => 'Get a multi-day weather forecast (up to 10 days) for any location worldwide.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'location' => ['type' => 'string', 'description' => 'City name, "City, Country", or "lat,lon"'],
                    'days'     => ['type' => 'integer', 'description' => 'Number of forecast days (1-10). Default: 5.', 'minimum' => 1, 'maximum' => 10, 'default' => 5],
                    'units'    => ['type' => 'string', 'enum' => ['metric', 'imperial'], 'default' => 'metric'],
                ],
                'required' => ['location'],
            ],
        ],
    ];

    return $tools;
}

function tools_hash(array $tools): string
{
    return 'sha256:' . hash('sha256', nais_canonical($tools));
}

// ─────────────────────────────────────────────────────────────────────────────
// Card assembly + signing
// ─────────────────────────────────────────────────────────────────────────────

function build_unsigned_card(): array
{
    $tools = snapshot_tools();

    return [
        'nais'        => '1.0',
        'cardVersion' => 8,
        'updated'     => STAMP,

        'name'        => 'WeatherAgent',
        'domain'      => 'weatheragent.nais.id',
        'description' => 'A demonstration NAIS-compliant weather agent providing current conditions, multi-day forecasts, and severe weather alerts for any location worldwide.',
        'tags'        => ['weather', 'forecast', 'climate', 'alerts', 'geospatial', 'demo'],
        'contact'     => 'demo@nais.id',

        'mcp'         => 'https://weatheragent.nais.id/mcp',

        'auth'        => [
            ['scheme' => 'none'],
        ],

        'payment'     => [
            'type'     => 'x402',
            'networks' => ['base'],
            'assets'   => ['USDC'],
            'payTo'    => ['0x742d35Cc6634C0532925a3b8D4C9B7F1A2e3d4E5'],
            'pricing'  => [
                'forecast'        => '0.001',
                'current_weather' => '0.0005',
                'alerts'          => '0.0005',
            ],
        ],

        // Pointers to related agents — partners, providers, recommendations.
        // Advisory only: 'verified' is the operator's signed attestation of an
        // established relationship; a client MUST still resolve and verify each
        // linked agent's own card before relying on it.
        'linkedAgents' => [
            ['domain' => 'alerts.weatheragent.nais.id', 'relation' => 'partner',     'name' => 'Severe Weather Alerts', 'verified' => true],
            ['domain' => 'geocode.nais.id',             'relation' => 'provider',    'name' => 'Geocoding Service',     'verified' => true],
            ['domain' => 'radar.example.org',           'relation' => 'recommended', 'name' => 'Community Radar',        'verified' => false],
        ],

        'mcpSnapshot' => [
            'capturedAt' => STAMP,
            'toolsHash'  => tools_hash($tools),
            'tools'      => $tools,
        ],
    ];
}

/**
 * Sign an unsigned card. Returns the card with a "signature" member appended,
 * plus the intermediate signing artifacts (for the test vector).
 */
function sign_card(array $card, string $secret, string $kid): array
{
    $payload      = nais_canonical($card);
    $header       = '{"alg":"EdDSA","kid":' . nais_canon_string($kid) . '}';
    $signingInput = b64url($header) . '.' . b64url($payload);
    $sig          = sodium_crypto_sign_detached($signingInput, $secret);
    $jws          = b64url($header) . '..' . b64url($sig);

    $card['signature'] = [
        'alg' => 'EdDSA',
        'kid' => $kid,
        'jws' => $jws,
    ];

    return [
        'card'          => $card,
        'payload'       => $payload,
        'header'        => $header,
        'signing_input' => $signingInput,
        'jws'           => $jws,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Main
// ─────────────────────────────────────────────────────────────────────────────

$key    = load_or_create_key();
$card   = build_unsigned_card();
$signed = sign_card($card, $key['secret'], $key['kid']);

$wantVector = in_array('--vector', $argv, true);

if ($wantVector) {
    // Emit the canonical test vector consumed by the SDK/resolver test suites.
    echo json_encode([
        'kid'            => $key['kid'],
        'dns_k'          => $key['kid'],                       // _agent TXT k= value
        'public_key_b64' => base64_encode($key['public']),
        'toolsHash'      => $card['mcpSnapshot']['toolsHash'],
        'signing_input'  => $signed['signing_input'],
        'jws'            => $signed['jws'],
        'card'           => $signed['card'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit;
}

// Write the published manifest (human-friendly key order; verification re-canonicalizes).
file_put_contents(
    MANIFEST_FILE,
    json_encode($signed['card'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);

fwrite(STDERR, "Wrote " . realpath(MANIFEST_FILE) . "\n");
fwrite(STDERR, "kid / DNS k= : " . $key['kid'] . "\n");
fwrite(STDERR, "toolsHash    : " . $card['mcpSnapshot']['toolsHash'] . "\n");
