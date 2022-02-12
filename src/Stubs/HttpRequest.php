<?php

namespace Borzo\Stubs;

class HttpRequest {
    public function header(string $string): string {
        return 'header value...';
    }

    public function bodyRaw(): string {
        return 'http request body...';
    }

    public function bodyJson(): array {
        return [ /* body json will be here... */ ];
    }

    public function isPost(): bool {
        return true;
    }
}
