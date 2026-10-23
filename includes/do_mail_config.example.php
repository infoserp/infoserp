<?php
/**
 * Example DO Dispatch mail settings.
 *
 * Copy to (outside wwwroot):
 *   C:\inetpub\Approval_config\do_mail_config.php
 *
 * The application loads that path automatically via includes/do_mail_config_loader.php.
 * Do not put real secrets in this wwwroot folder.
 */
return [
    'mail_driver' => 'graph',
    'test_to'     => 'your-test@example.com',

    'graph' => [
        'tenant_id'     => '',
        'client_id'     => '',
        'client_secret' => '',
        'from_email'    => 'Deliveries@fandoes.com',
        'from_name'     => 'Fandoes Deliveries',
        'timeout'       => 30,
    ],

    'smtp_host'       => 'smtp.office365.com',
    'smtp_port'       => 587,
    'smtp_secure'     => 'tls',
    'connect_timeout' => 15,
    'smtp_user'       => 'Deliveries@fandoes.com',
    'smtp_pass'       => '',
    'from_email'      => 'Deliveries@fandoes.com',
    'from_name'       => 'Fandoes Deliveries',
];
