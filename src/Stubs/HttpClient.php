<?php

namespace Borzo\Stubs;

class HttpClient {
    public function buildRequest(string $method, string $url, string $data) {
        return $this;
    }

    public function header(string $name, string $value) {
        return $this;
    }

    public function send() {
        return new HttpResponse();
    }
}
