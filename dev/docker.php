<?php
/**
 * Docker of the dev harness. `rules` are the Docker extension's proxy rules, by website name instead
 * of Plesk's domain ID. `containers` is what Docker answers to /containers/json?all=1, and `inspect`
 * what it answers to /containers/<id>/json (only asked for stopped containers, which list no ports).
 */
return [
    'rules' => [
        ['domain' => 'shop.example.net', 'path' => '', 'port' => '18080', 'host' => '127.0.0.1'],
        ['domain' => 'blog.example.org', 'path' => 'api', 'port' => '18081', 'host' => '127.0.0.1'],
    ],
    'sslRedirect' => ['shop.example.net'],
    'containers' => [
        [
            'Id' => str_repeat('a1', 32),
            'Names' => ['/shop'],
            'Image' => 'shop:2026-10-28',
            'State' => 'running',
            'Status' => 'Up 12 hours (healthy)',
            'Ports' => [['IP' => '127.0.0.1', 'PrivatePort' => 8080, 'PublicPort' => 18080, 'Type' => 'tcp']],
        ],
        [
            'Id' => str_repeat('b2', 32),
            'Names' => ['/blog-api'],
            'Image' => 'blog-api:1.4.0',
            'State' => 'exited',
            'Status' => 'Exited (1) 3 minutes ago',
            'Ports' => [],
        ],
        [
            'Id' => str_repeat('c3', 32),
            'Names' => ['/redis-cache'],
            'Image' => 'redis:7.4-alpine',
            'State' => 'running',
            'Status' => 'Up 2 days',
            'Ports' => [['IP' => '127.0.0.1', 'PrivatePort' => 6379, 'PublicPort' => 6379, 'Type' => 'tcp']],
        ],
    ],
    'inspect' => [
        str_repeat('b2', 32) => ['HostConfig' => ['PortBindings' => ['8080/tcp' => [['HostIp' => '127.0.0.1', 'HostPort' => '18081']]]]],
    ],
];
