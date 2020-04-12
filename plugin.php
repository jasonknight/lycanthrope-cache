<?php
/**
 * Plugin Name: Lycanthrope Cache
 * Plugin URI: https://lycanthropenoir.com
 * Description: A simple page and partial caching plugin for developers. No automatic caching!
 * Version: 1.0
 * Author: Jason Martion <contact@lycanthropenoir.com>
 * Author URI: https://app.codeable.io/tasks/new?preferredContractor=43500&ref=76T6q
 * License: Private
 */
namespace Lycanthrope\Cache;
require_once(__DIR__ . '/settings.php');
const SECONDS       = 1;
const MINUTES       = SECONDS * 60;
const HOURS         = MINUTES * 60;
const DAYS          = HOURS * 24;
const WEEKS         = DAYS * 7;
function Milliseconds($n) {
    return $n * MILLISECONDS;
}
function Seconds($n) {
    return $n * SECONDS;
}
function Minutes($n) {
    return $n * MINUTES;
}
function Hours($n) {
    return $n * HOURS;
}
function Days($n) {
    return $n * Days;
}
function Weeks($n) {
    return $n * WEEKS;
}
function get_fs() {
    return function () {
        global $wp_filesystem;
        if ( ! function_exists('\WP_Filesystem') ) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            \WP_Filesystem();
        }
        return $wp_filesystem;
    };
}
function get_expiration_time($ttl) {
    $time = time();
    return $time + $ttl;
}
function create_cacheable_object($data,$ttl) {
    $exp = get_expiration_time($ttl);
    $obj = [
        'when' => $exp,
        'cached_at' => date('Y-m-d H:i:s'),
        'date' => date("Y-m-d H:i:s",$exp),
        'what' => $data,
    ];
    return "{$obj['when']};{$obj['cached_at']};{$obj['date']};{$obj['what']}";
}
/*
 * We do it this way to avoid the possibility of code injection.
 * it's a simple file formate
 * expiration;cached_at_date;expiration_date;....the data...
 * */
function read_cacheable_object($data) {
    $buffer = '';
    $i = 0;
    $cnt = strlen($data);
    $semi_colons = 0;
    $parts = [];
    while ( $i < $cnt ) {
        if ( $data[$i] == ';' && $semi_colons < 3 ) {
            $parts[] = $buffer;
            $buffer = '';
            $semi_colons++;
        } else {
            $buffer .= $data[$i];
        }
        $i++;
    }
    list($when,$cached_at,$date) = $parts;
    return [
        'when' => intval($when),
        'cached_at' => $cached_at,
        'date' => $date,
        'what' => $buffer,
    ];
}
function write_file_contents($path,$data) {
    $fs = apply_filters('wp_filesystem_for_lycan_cache', get_fs())();
    if ( ! $fs->is_dir(dirname($path)) ) {
        //@mkdir(dirname($path),0777,true);
        $parts = explode('/',dirname($path));
        $path_to_make = array_shift($parts);
        while (!empty($parts)) {
            if ( ! $fs->is_dir($path_to_make) ) {
                $fs->mkdir($path_to_make);
            }
            $path_to_make .= "/" . array_shift($parts);
        }
        // and 1 last time :)
        $fs->mkdir(dirname($path));
    }
    $fs->put_contents($path,$data);
}
function get_path_from_key($key) {
    $fs = apply_filters('wp_filesystem_for_lycan_cache', get_fs())();
    $hash = hash('sha1',$key);
    preg_match_all("/([a-f0-9]{2})/",$hash,$m);
    $parts = array_slice($m[1],0,4);
    array_unshift( $parts,\sanitize_file_name(__NAMESPACE__) );
    array_unshift( $parts,$fs->wp_content_dir() );
    $parts[] = $hash . ".php";
    $path = join('/',$parts);
    do_action('lycan_log',"path for $key is $path",__NAMESPACE__);
    return str_replace('//','/',$path);
}
function Filesystem_Store($key,$obj,$ttl) {
    $s = settings();
    $path = get_path_from_key($key); 
    $data = create_cacheable_object($obj,$ttl);
    do_action('lycan_log',"writing $key",__NAMESPACE__);
    write_file_contents($path,$data);
}
function Filesystem_Expire($key) {
    $s = settings();
    $path = get_path_from_key($key); 
    if ( file_exists($path) ) {
        do_action('lycan_log',"unlinking $key",__NAMESPACE__);
        unlink($path);
    }
}
function Filesystem_Load($key) {
    $s = settings();
    $path = get_path_from_key($key); 
    if ( !file_exists($path) )
        return null;
    $data = file_get_contents($path);
    $data = read_cacheable_object($data); 
    do_action('lycan_log', (function () use ($data) { 
        unset($data['what']);
        return $data;
    })(),__NAMESPACE__);
    $time = time();
    if ( $time > $data['when'] ) {
        do_action('lycan_log',"$key is expired, returning null",__NAMESPACE__);
        Filesystem_Expire($key);
        return null;
    }
    do_action('lycan_log',"$key is not expired, returning data",__NAMESPACE__);
    return $data['what'];
}
function get_load_function() {
    $s = settings();
    return function ($key) use ($s) {
        return Filesystem_Load($key);
    };
}
function get_store_function() {
    $s = settings();
    return function ($key,$ttl,$content) use ($s) {
        return Filesystem_Store($key,$content,$ttl);  
    };
}
function get_expire_function() {
    $s = settings();
    return function($key) use ($s){
        Filesystem_Expire($key);
    };
}
function _simple_loader($key,$ttl,$callable,$result_type) {
    $load = apply_filters('load_function_for_lycan_cache',get_load_function());
    $store = apply_filters('store_function_for_lycan_cache',get_store_function()); 
    $data = $load($key);
    /* Somehow I feel this could be streamlined and less
     * convoluted
     */
    if ( $data ) {
        if ( $result_type == 'return' ) {
            return function () use($data) {
                return $data; 
            };
        } else {
            return function () use($data) {
                echo $data; 
            };
        }
    }
    // So there was no cache, let's create and return
    if ( $result_type == 'return' ) {
        $data = call_user_func_array($callable,[$key,$ttl]);
        return function () use ($data) {
            return $data;
        };
    } else {
        ob_start();
            call_user_func_array($callable,[$key,$ttl]);
            $data = ob_get_contents();
        ob_end_clean();
        $store($key,$ttl,$data);
        return function () use ($data) {
            echo $data;
        };
    }
}
function init() {
    $s = settings();
    \add_filter('load_lycan_cache_by_key',function ($callable,$key,$ttl,$result_type) {
        return _simple_loader($key,$ttl,$callable,$result_type); 
    },10,4); 
    \add_filter('load_lycan_cache_by_key_scoped_by_user',function ($callable,$key,$ttl,$result_type) {
        $user = wp_get_current_user();  
        $key = "{$user->ID}_{$key}";
        return _simple_loader($key,$ttl,$callable,$result_type); 
    },10,4);
    \add_filter('expire_lycan_cache_by_key',function ($key) {
        $expire = get_expire_function();
        $expire($key); 
    },10);
    \add_filter('expire_lycan_cache_by_key_scoped_by_user',function ($key) {
        if ( is_logged_in() )
            $user = wp_get_current_user();  
            $key = "{$user->ID}_{$key}";
        } else {
            $key = "0_{$key}";
        }
        $expire = apply_filters('expire_function_for_lycan_cache',get_expire_function());
        $expire($key); 
    },10);
    \add_filter('expire_lycan_cache_by_key_scoped_by_logged_in',function ($key) {
        if ( is_logged_in() ) {
            $key = "logged_in{$key}";
        } else {
            $key = "!logged_in{$key}";
        }
        $expire = apply_filters('expire_function_for_lycan_cache',get_expire_function());
        $expire($key); 
    },10);
    if ( $s->logging == 'on' ) {
        \add_action('lycan_log',function ($msg,$source='') use($s) {
            //if ( $source != __NAMESPACE__ )
            file_put_contents($s->log_file_path, print_r([$msg,$source],true) . PHP_EOL,FILE_APPEND);
        },10,2);
    }
}
\add_action('wp_loaded', __NAMESPACE__ . '\init');
