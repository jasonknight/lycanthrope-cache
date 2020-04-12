<?php
namespace Lycanthrope\Cache;
function settings() {
    $o = new \stdClass;
    $o->logging = 'on';
    $o->log_file_path = __DIR__ . '/lycan.log';
    return $o;
}
