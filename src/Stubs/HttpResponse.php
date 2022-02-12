<?php

namespace Borzo\Stubs;

class HttpResponse {
    public $body;
    public $status;

    public function getJson(): array {
        return [ /* json data... */ ];
    }
}
