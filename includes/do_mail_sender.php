<?php
/**
 * Unified DO Dispatch mail entry point — Graph (default) or SMTP fallback.
 */

require_once __DIR__ . '/do_graph_mailer.php';
require_once __DIR__ . '/ms365_mailer.php';

class DoMailSender
{
    /**
     * @return array{mailer: object, log: callable, driver: string}
     */
    public static function create(array $config)
    {
        $driver = strtolower((string) ($config['mail_driver'] ?? 'graph'));

        if ($driver === 'smtp') {
            return [
                'driver' => 'smtp',
                'mailer' => new Ms365Mailer($config),
                'log'    => function ($mailer) {
                    return $mailer instanceof Ms365Mailer ? $mailer->getLog() : [];
                },
            ];
        }

        $graphConfig = $config['graph'] ?? $config;

        return [
            'driver' => 'graph',
            'mailer' => new DoGraphMailer($graphConfig),
            'log'    => function ($mailer) {
                return $mailer instanceof DoGraphMailer ? $mailer->getLog() : [];
            },
        ];
    }

    public static function send(array $config, $to, $subject, $htmlBody, $textBody = null, array $attachments = [])
    {
        $ctx    = self::create($config);
        $mailer = $ctx['mailer'];
        $mailer->send($to, $subject, $htmlBody, $textBody, $attachments);

        return [
            'driver' => $ctx['driver'],
            'log'    => $ctx['log']($mailer),
        ];
    }
}
