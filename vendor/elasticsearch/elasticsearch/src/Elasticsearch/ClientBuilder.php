<?php
namespace Elasticsearch;

class ClientBuilder {
    public static function fromConfig($config = [], $quiet = false) { return new Client(); }
}

class Client {
    public function __call($name, $args) { return null; }
}
