<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

echo json_encode([
    'name'     => 'WeatherAgent',
    'standard' => 'nais',
    'version'  => '1.0.0',
    'domain'   => 'weatheragent.nais.id',
    'manifest' => 'https://weatheragent.nais.id/.well-known/agent.json',
    'mcp'      => 'https://weatheragent.nais.id/mcp',
    'docs'     => 'https://nais.id/demo',
    'status'   => 'operational',
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
