# protobuf-php-plus

Generate modern, strictly typed PHP classes from Protocol Buffer schemas.

`protoc` generates untyped properties hidden behind getters:

```php
protected $name = '';

public function getName(){ /* ... */ }
public function setName($value){ /* ... */ }
```

`protop` generates the same classes with native PHP 8.4 types and publicly
readable properties:

```php
protected(set) string $name = '';

public function getName(): string { /* ... */ }
public function setName(string $value): self { /* ... */ }
```

```sh
composer require cosmicpe/protobuf-php-plus
vendor/bin/protop --proto_path=proto --php_out=generated proto/messages.proto
```

Use `protop` exactly like `protoc`. Requires PHP 8.4+ and `protoc` on PATH.
