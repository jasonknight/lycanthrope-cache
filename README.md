# Lycanthrope - Cache

This is a developer plugin for WordPress that implements partial caching. It does not
do this automatically, you must use the filters provided by the plugin to cache certain
malperforming page elements.

Because of the nature of WordPress, and some plugins and themes, you cannot really "cache"
everything. This plugin is specifically designed so that you can cache parts of your own
custom development in the theme's functions.php or in a side or custom plugin.

## Basic Partial Caching

You wrap what you want to cache in an anonymous function. If the cache is present, it
is loaded, otherwise, your function is executed, a cache is written etc.

```php
<?php
    apply_filters('load_lycan_cache_by_key',function ($key,$ttl) {
        do_action( 'storefront_loop_before' );
        while ( have_posts() ) :
            the_post();

            /**
             * Include the Post-Format-specific template for the content.
             * If you want to override this in a child theme, then include a file
             * called content-___.php (where ___ is the Post Format name) and that will be used instead.
             */
            get_template_part( 'content', get_post_format() );

        endwhile;

        /**
         * Functions hooked in to storefront_paging_nav action
         *
         * @hooked storefront_paging_nav - 10
         */
        do_action( 'storefront_loop_after' );
    },'wp_loop',\Lycanthrope\Cache\Minutes(5),'echo')();
```

The filter definition is:

```php
<?php
    add_filter('load_lycan_cache_by_key', $callable,$keyname,$expiry_in_seconds,$return_type)();
```

Notice the final `()`, this is because you are passing a function. If you deactivate the caching plugin, then 
your code will still run as if nothing happened. The filters do not return values, they return callable 
functions that have the return values bound, and either use a `return` or `echo` depending on the `$return_type` 
argument.

## Scoped Partial Caching

In some instances you want to cache based on whether or not the user is logged in, or per user. There are two
filters provided for this, `load_lycan_cache_by_key_scoped_by_user` and `load_lycan_cache_by_key_scoped_by_logged_in`.

When scoping by user, it is the currently logged_in user. When scoping by logged_in it is just whether or not the user is logged in. The logged_in scope creates only two versions, whereas the by_user creates an entry for every single user. 
and one for a '0' user (i.e. not logged in).


