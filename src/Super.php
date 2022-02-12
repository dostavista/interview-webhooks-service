<?php

namespace Borzo;

use Borzo\Stubs\HttpClient;
use Borzo\Stubs\HttpRequest;
use Borzo\Stubs\Log;

class Super {
    public static function getProjectAssignees(): array {
        return include __DIR__ . '/../configs/project-assignees.php';
    }

    public static function getLog(): Log {
        return new Log();
    }

    public static function getHttpRequest(): HttpRequest {
        return new HttpRequest();
    }

    public static function createHttpClient(): HttpClient {
        return new HttpClient();
    }

    public static function getConfig(): Config {
        return new Config();
    }
}
