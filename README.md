# AJAX Action Wrapper

This helper library makes it easier to handle AJAX requests in WordPress plugins. Mainly for personal use.

### Example
Define action:
```php
$exampleAction = ajaw_v1_CreateAction('ws_do_something')
	->handler(array($this, 'myAjaxCallback'))
	->requiredCap('manage_options')
	->method('post')
	->requiredParam('foo')
	->optionalParam('bar', 'default value')
	->register();
```

Call from JavaScript:
```javascript
AjawV1.getAction('ws_do_something').post(
	{
		'foo': '...'
	},
	function(response) {
		console.log(response);
	}
);
```

### Features
- Automate common, boring stuff.
	- [x] Automatically pass the `admin-ajax.php` URL and nonce to JS.
	- [x] Define required parameters.
		```php
		$builder->requiredParam('foo', 'int')
		```
	- [x] Define optional parameters with default values.
		```php
		$builder->optionalParam('meaningOfLife', 42, 'int')
		```
	- [x] Automatically remove "magic quotes" that WordPress adds to `$_GET`, `$_POST` and `$_REQUEST`.
	- [x] Encode return values as JSON.
- Security should be the default.
	- [x] Generate and verify nonces. Nonce verification is on by default, but can be disabled.
		```php
		$builder->withoutNonce()
		```
	- [x] Check capabilities.
		```php
		$builder->requiredCap('manage_options');
		```
	- [x] Verify that all required parameters are set.
	- [x] Validate parameter values.
		```php
		$builder->optionalParam('things', 1, 'int', function($value) {
			if ($value > 10) {
				return new WP_Error(
					'excessive_things',
					'Too many things!',
					400 //HTTP status code.
				);
			}
		})
		```
	- [x] Set the required HTTP method.
		```php
		$builder->method('post')
		```
- Resilience.
	- [ ] Lenient response parsing to work around bugs in other plugins. For example, deal with extraneous whitespace and PHP notices in AJAX responses.
	- [x] Multiple versions of the library can coexist on the same site.

### Why not use the REST API instead?

Backwards compatibility. In theory, this library should be compatible with WP 4.1+.