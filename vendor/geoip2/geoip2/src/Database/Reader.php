<?php
namespace GeoIp2\Database;

class Reader {
    public function __construct($filename = '', $locales = ['en']) {}
    public function country($ipAddress) { return null; }
    public function close() {}
}
