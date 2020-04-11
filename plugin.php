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
    $time = round(microtime(true) * 1000);
    return $time + $ttl;
}
function create_cacheable_object($data,$ttl) {
    $obj = [
        'when' => get_expiration_time($ttl),
        'what' => $data,
    ];
    return var_export($obj,true);
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
    return str_replace('//','/',$path);
}
function Filesystem_Store($key,$obj,$ttl) {
    $s = settings();
    $path = get_path_from_key($key); 
    $data = create_cacheable_object($obj,$ttl);
    $data = "<?php return $data; ?>"; // this is to fix the syntax highlighting in stackedit <?php
    write_file_contents($path,$data);
}
function Filesystem_Expire($key) {
    $s = settings();
    $path = get_path_from_key($key); 
    if ( file_exists($path) )
        unlink($path);
}
function Filesystem_Load($key) {
    $s = settings();
    $path = get_path_from_key($key); 
    if ( !file_exists($path) )
        return null;
    $data = include($path);
    $time = round(microtime(true) * 1000);
    if ( $time > $data['when'] )
        return null;
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
    if ( $data )
        return $data;
    if ( $result_type == 'return' ) {
        $data = call_user_func_array($callable,[$key,$ttl]);
    } else {
        ob_start();
            call_user_func_array($callable,[$key,$ttl]);
            $data = ob_get_contents();
        ob_end_clean();
    }
    $store($key,$ttl,$data);
    return $data;
}
function init() {
    \add_filter('load_lycan_cache_by_key',function ($key,$ttl,$callable,$result_type) {
        return _simple_loader($key,$ttl,$callable,$result_type); 
    },10,4); 
    \add_filter('load_lycan_cache_by_key_scoped_by_user',function ($key,$ttl,$callable,$result_type) {
        $user = wp_get_current_user();  
        $key = "{$user->ID}_{$key}";
        return _simple_loader($key,$ttl,$callable,$result_type); 
    },10,4);
    \add_filter('expire_lycan_cache_by_key',function ($key) {
        $expire = get_expire_function();
        $expire($key); 
    },10);
    \add_filter('expire_lycan_cache_by_key_scoped_by_user',function ($key) {
        $user = wp_get_current_user();  
        $key = "{$user->ID}_{$key}";
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
}
\add_action('wp_loaded', __NAMESPACE__ . '\init');
