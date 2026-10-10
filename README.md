# protibimbok/wp-vite

PHP side of [wp-vite](../wp-vite-plugin): registers the entries, chunks and styles that the
`wp-vite-plugin` Vite plugin writes to `build/wp-vite.php` through WordPress's Script Modules API,
and swaps them for dev-server URLs while `vite` is running.

Requires PHP 8.1+ and WordPress 6.7+.

```
composer require protibimbok/wp-vite
```

Documentation follows once the API is complete.
