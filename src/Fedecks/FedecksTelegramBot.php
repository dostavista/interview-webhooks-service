<?php

namespace Borzo\Fedecks;

use Borzo\Super;

class FedecksTelegramBot {
    private const URL = 'https://dv-fedecks-bot.borzo.net';

    public static function sendGithubPayloadToFedecks(string $payloadString): void {
        $url = self::URL . '/github-hook';

        self::sendPayloadToFedecks($url, $payloadString);
    }

    public static function sendGitlabPayloadToFedecks(string $payloadString) {
        $url = self::URL . '/gitlab-hook';

        self::sendPayloadToFedecks($url, $payloadString);
    }

    private static function sendPayloadToFedecks(string $url, string $payloadString): void {
        Super::createHttpClient()
            ->buildRequest('POST', $url, $payloadString)
            ->header('Content-Type', 'application/json')
            ->send();
    }
}
