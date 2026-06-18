<?php
/**
 * WeatherAgent MCP Endpoint — Demo NAIS MCP Server
 * Implements JSON-RPC 2.0 over HTTP POST.
 * Methods: initialize, tools/list, tools/call
 * PHP 7.4+, no external dependencies.
 */

declare(strict_types=1);

// ─── CORS Headers ─────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Payment');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Handle OPTIONS pre-flight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed. Use POST.']);
    exit;
}

// ─── Parse request body ───────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
if (empty($raw)) {
    sendError(null, -32700, 'Parse error: empty request body');
    exit;
}

$req = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    sendError(null, -32700, 'Parse error: invalid JSON — ' . json_last_error_msg());
    exit;
}

// ─── Validate JSON-RPC 2.0 envelope ──────────────────────────────────────────
if (!isset($req['jsonrpc']) || $req['jsonrpc'] !== '2.0') {
    sendError($req['id'] ?? null, -32600, 'Invalid Request: jsonrpc must be "2.0"');
    exit;
}
if (!isset($req['method']) || !is_string($req['method'])) {
    sendError($req['id'] ?? null, -32600, 'Invalid Request: method is required');
    exit;
}

$id     = $req['id'] ?? null;
$method = $req['method'];
$params = $req['params'] ?? [];

// ─── Dispatch ─────────────────────────────────────────────────────────────────
switch ($method) {
    case 'initialize':
        handleInitialize($id, $params);
        break;

    case 'tools/list':
        handleToolsList($id);
        break;

    case 'tools/call':
        handleToolsCall($id, $params);
        break;

    default:
        sendError($id, -32601, "Method not found: {$method}");
}

// ─── Handlers ─────────────────────────────────────────────────────────────────

function handleInitialize($id, array $params): void
{
    sendResult($id, [
        'protocolVersion' => '2024-11-05',
        'serverInfo' => [
            'name'    => 'WeatherAgent',
            'version' => '1.0.0',
        ],
        'capabilities' => [
            'tools' => ['listChanged' => false],
        ],
        'instructions' => 'WeatherAgent provides weather forecast, current conditions, historical data, and severe weather alerts. Call tools/list to see available tools. All location parameters accept city names, "City, Country" strings, or "lat,lon" coordinates.',
    ]);
}

function handleToolsList($id): void
{
    sendResult($id, [
        'tools' => [
            [
                'name'        => 'forecast',
                'description' => 'Get a multi-day weather forecast (up to 10 days) for any location worldwide.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'location' => [
                            'type'        => 'string',
                            'description' => 'City name, "City, Country", or "lat,lon"',
                        ],
                        'days' => [
                            'type'        => 'integer',
                            'description' => 'Number of forecast days (1–10). Default: 5.',
                            'minimum'     => 1,
                            'maximum'     => 10,
                            'default'     => 5,
                        ],
                        'units' => [
                            'type'        => 'string',
                            'enum'        => ['metric', 'imperial'],
                            'description' => 'Unit system. Default: metric.',
                            'default'     => 'metric',
                        ],
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
                        'location' => [
                            'type'        => 'string',
                            'description' => 'City name, "City, Country", or "lat,lon"',
                        ],
                        'units' => [
                            'type'        => 'string',
                            'enum'        => ['metric', 'imperial'],
                            'default'     => 'metric',
                        ],
                    ],
                    'required' => ['location'],
                ],
            ],
            [
                'name'        => 'alerts',
                'description' => 'Get active severe weather alerts for a region. Returns empty array if no active alerts.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'location' => [
                            'type'        => 'string',
                            'description' => 'City name, region, state/country code, or "lat,lon"',
                        ],
                        'severity' => [
                            'type'        => 'string',
                            'enum'        => ['all', 'extreme', 'severe', 'moderate', 'minor'],
                            'default'     => 'all',
                        ],
                    ],
                    'required' => ['location'],
                ],
            ],
        ],
    ]);
}

function handleToolsCall($id, array $params): void
{
    if (!isset($params['name']) || !is_string($params['name'])) {
        sendError($id, -32602, 'Invalid params: "name" is required');
        return;
    }

    $toolName   = $params['name'];
    $toolParams = $params['arguments'] ?? [];

    switch ($toolName) {
        case 'forecast':
            callForecast($id, $toolParams);
            break;
        case 'current_weather':
        case 'current':
            callCurrent($id, $toolParams);
            break;
        case 'alerts':
            callAlerts($id, $toolParams);
            break;
        default:
            sendError($id, -32602, "Unknown tool: {$toolName}");
    }
}

// ─── Tool Implementations ──────────────────────────────────────────────────────

function callForecast($id, array $params): void
{
    if (empty($params['location'])) {
        sendError($id, -32602, 'Invalid params: "location" is required');
        return;
    }

    $location = (string) $params['location'];
    $days     = isset($params['days']) ? max(1, min(10, (int) $params['days'])) : 5;
    $units    = ($params['units'] ?? 'metric') === 'imperial' ? 'imperial' : 'metric';

    $tempUnit = $units === 'imperial' ? '°F' : '°C';
    $windUnit = $units === 'imperial' ? 'mph' : 'km/h';

    // Generate deterministic-looking mock data seeded by location string
    $seed       = crc32(strtolower($location));
    $conditions = ['Clear', 'Partly Cloudy', 'Cloudy', 'Light Rain', 'Rain', 'Thunderstorm', 'Snow', 'Fog', 'Windy'];
    $baseTemp   = $units === 'imperial' ? 68 : 20;
    $baseTempF  = ($seed % 30) - 10; // -10 to +20 offset

    $forecast = [];
    $date     = new DateTimeImmutable('today');

    for ($i = 0; $i < $days; $i++) {
        $dayOffset   = ($seed + $i * 17) % 9;
        $conditionIdx = ($dayOffset + $i) % count($conditions);
        $high        = $baseTemp + $baseTempF + ($i % 5) - 2 + ($conditionIdx < 3 ? 2 : -1);
        $low         = $high - 8 - ($i % 3);
        $precipProb  = $conditionIdx >= 3 && $conditionIdx <= 5 ? 60 + ($conditionIdx * 5) : ($conditionIdx === 6 ? 80 : 10 + ($i * 3 % 20));
        $windSpeed   = 8 + ($seed + $i * 7) % 30;

        $forecast[] = [
            'date'           => $date->modify("+{$i} days")->format('Y-m-d'),
            'condition'      => $conditions[$conditionIdx],
            'condition_code' => $conditionIdx,
            'temp_high'      => round($high, 1),
            'temp_low'       => round($low, 1),
            'temp_unit'      => $tempUnit,
            'precip_prob'    => min(100, $precipProb),
            'precip_mm'      => $precipProb > 40 ? round(($precipProb - 40) * 0.3, 1) : 0,
            'wind_speed'     => $windSpeed,
            'wind_unit'      => $windUnit,
            'humidity'       => 45 + ($seed + $i * 13) % 40,
            'uv_index'       => max(0, min(11, 6 - $conditionIdx + ($i % 3))),
            'sunrise'        => '06:' . str_pad((string)(($seed + $i) % 30 + 15), 2, '0', STR_PAD_LEFT),
            'sunset'         => '19:' . str_pad((string)(($seed + $i * 3) % 30 + 15), 2, '0', STR_PAD_LEFT),
        ];
    }

    $result = [
        'location'       => resolveLocation($location),
        'units'          => $units,
        'generated_at'   => gmdate('Y-m-d\TH:i:s\Z'),
        'forecast_days'  => count($forecast),
        'forecast'       => $forecast,
        'source'         => 'WeatherAgent Demo (mock data)',
        'disclaimer'     => 'This is demonstration data for NAIS integration testing. Not for operational use.',
    ];

    sendToolResult($id, $result);
}

function callCurrent($id, array $params): void
{
    if (empty($params['location'])) {
        sendError($id, -32602, 'Invalid params: "location" is required');
        return;
    }

    $location = (string) $params['location'];
    $units    = ($params['units'] ?? 'metric') === 'imperial' ? 'imperial' : 'metric';
    $tempUnit = $units === 'imperial' ? '°F' : '°C';
    $windUnit = $units === 'imperial' ? 'mph' : 'km/h';

    $seed       = crc32(strtolower($location));
    $conditions = ['Clear Sky', 'Partly Cloudy', 'Overcast', 'Light Drizzle', 'Moderate Rain', 'Thunderstorm'];
    $condIdx    = $seed % count($conditions);
    $temp       = ($units === 'imperial' ? 65 : 18) + ($seed % 25) - 5;

    $result = [
        'location'         => resolveLocation($location),
        'units'            => $units,
        'observed_at'      => gmdate('Y-m-d\TH:i:s\Z'),
        'condition'        => $conditions[$condIdx],
        'condition_code'   => $condIdx,
        'temperature'      => round($temp, 1),
        'feels_like'       => round($temp - 2 + ($seed % 5), 1),
        'temp_unit'        => $tempUnit,
        'humidity'         => 50 + ($seed % 35),
        'pressure_hpa'     => 1008 + ($seed % 20) - 5,
        'visibility_km'    => $condIdx >= 3 ? round(5 + ($seed % 8), 1) : round(15 + ($seed % 10), 1),
        'wind_speed'       => 5 + ($seed % 30),
        'wind_direction'   => ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'][$seed % 8],
        'wind_unit'        => $windUnit,
        'uv_index'         => max(0, min(11, 7 - $condIdx)),
        'cloud_cover_pct'  => $condIdx * 16,
        'dew_point'        => round($temp - 8 - ($seed % 6), 1),
        'source'           => 'WeatherAgent Demo (mock data)',
        'disclaimer'       => 'This is demonstration data for NAIS integration testing. Not for operational use.',
    ];

    sendToolResult($id, $result);
}

function callAlerts($id, array $params): void
{
    if (empty($params['location'])) {
        sendError($id, -32602, 'Invalid params: "location" is required');
        return;
    }

    $location = (string) $params['location'];
    $seed     = crc32(strtolower($location));

    // ~30% chance of having alerts (deterministic by location)
    $hasAlerts = ($seed % 10) < 3;

    $alerts = [];
    if ($hasAlerts) {
        $alertTypes = [
            [
                'event'     => 'Wind Advisory',
                'severity'  => 'moderate',
                'certainty' => 'likely',
                'urgency'   => 'expected',
                'headline'  => 'Wind Advisory in effect until 6 PM local time',
                'description' => 'Southwest winds 25 to 35 mph with gusts up to 50 mph expected. Gusty winds could blow around unsecured objects. Tree limbs could be blown down and a few power outages may result.',
            ],
            [
                'event'     => 'Flash Flood Watch',
                'severity'  => 'severe',
                'certainty' => 'possible',
                'urgency'   => 'future',
                'headline'  => 'Flash Flood Watch through tomorrow morning',
                'description' => 'Heavy rainfall of 2 to 3 inches possible over the next 24 hours. Flash flooding is possible in low-lying areas and near small streams.',
            ],
            [
                'event'     => 'Dense Fog Advisory',
                'severity'  => 'minor',
                'certainty' => 'observed',
                'urgency'   => 'immediate',
                'headline'  => 'Dense Fog Advisory until 10 AM local time',
                'description' => 'Visibility one quarter mile or less in dense fog. If driving, slow down, use your headlights, and leave plenty of distance ahead of you.',
            ],
        ];

        $alertIdx = $seed % count($alertTypes);
        $alert    = $alertTypes[$alertIdx];
        $alert['id']         = 'WX-' . strtoupper(substr(md5($location), 0, 8));
        $alert['area']       = resolveLocation($location)['display_name'] ?? $location;
        $alert['issued']     = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
        $alert['expires']    = gmdate('Y-m-d\TH:i:s\Z', time() + 18 * 3600);
        $alerts[]            = $alert;
    }

    $result = [
        'location'     => resolveLocation($location),
        'queried_at'   => gmdate('Y-m-d\TH:i:s\Z'),
        'alert_count'  => count($alerts),
        'alerts'       => $alerts,
        'source'       => 'WeatherAgent Demo (mock data)',
        'disclaimer'   => 'This is demonstration data for NAIS integration testing. Not for real emergency use.',
    ];

    sendToolResult($id, $result);
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Build a normalized location object from an input string.
 */
function resolveLocation(string $input): array
{
    $input = trim($input);

    // lat,lon
    if (preg_match('/^(-?\d+\.?\d*),\s*(-?\d+\.?\d*)$/', $input, $m)) {
        return [
            'display_name' => "Location ({$m[1]}, {$m[2]})",
            'lat'          => (float) $m[1],
            'lon'          => (float) $m[2],
            'type'         => 'coordinates',
        ];
    }

    // city, country
    if (strpos($input, ',') !== false) {
        [$city, $country] = array_map('trim', explode(',', $input, 2));
        return [
            'display_name' => "{$city}, {$country}",
            'city'         => $city,
            'country'      => strtoupper($country),
            'type'         => 'city_country',
        ];
    }

    return [
        'display_name' => $input,
        'city'         => $input,
        'type'         => 'city',
    ];
}

/**
 * Send a successful JSON-RPC 2.0 result.
 */
function sendResult($id, $result): void
{
    echo json_encode([
        'jsonrpc' => '2.0',
        'id'      => $id,
        'result'  => $result,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send a successful tools/call result with MCP content envelope.
 */
function sendToolResult($id, $data): void
{
    sendResult($id, [
        'content' => [
            [
                'type' => 'text',
                'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            ],
        ],
        'isError' => false,
    ]);
}

/**
 * Send a JSON-RPC 2.0 error response.
 */
function sendError($id, int $code, string $message): void
{
    echo json_encode([
        'jsonrpc' => '2.0',
        'id'      => $id,
        'error'   => [
            'code'    => $code,
            'message' => $message,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
