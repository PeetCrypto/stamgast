<?php
/**
 * Email configuration
 * 
 * Configuration for email system
 * 
 * @package STAMGAST
 * @subpackage Config
 */

return [
    // Default provider
    'default_provider' => 'brevo',
    
    // Provider configurations
    'providers' => [
        'brevo' => [
            'api_key' => $_ENV['BREVO_API_KEY'] ?? '',
            'smtp' => [
                'host' => 'smtp-relay.brevo.com',
                'port' => 587,
                'encryption' => 'tls'
            ]
        ],
        'sender_net' => [
            'api_key' => $_ENV['SENDER_NET_API_KEY'] ?? '',
            'smtp' => [
                'host' => 'smtp.sender.net',
                'port' => 587,
                'encryption' => 'tls'
            ]
        ],
        'aws_ses' => [
            'access_key' => $_ENV['AWS_ACCESS_KEY'] ?? '',
            'secret_key' => $_ENV['AWS_SECRET_KEY'] ?? '',
            'region' => $_ENV['AWS_REGION'] ?? 'eu-west-1',
            'smtp' => [
                'host' => 'email-smtp.' . ($_ENV['AWS_REGION'] ?? 'eu-west-1') . '.amazonaws.com',
                'port' => 587,
                'encryption' => 'tls'
            ]
        ]
    ],
    
    // Default sender information
    'sender' => [
        'email' => 'no-reply@regulr.vip',
        'name' => 'REGULR.vip'
    ],
    
    // Encryption key for sensitive data
    'encryption_key' => $_ENV['ENCRYPTION_KEY'] ?? 'default_key_change_in_production'
];