# Container

A lightweight, PHP 5.4+ compatible, PSR-11 dependency injection container.

## Usage

Basic manipulation of items.

```php
<?php

use WP_Forge\Container\Container;

// Create a new instance
$container = new Container();

// Set a value
$container->set('email', 'webmaster@site.com');

// Check if a value exists
$exists = $container->has('email');

// Get a value
$value = $container->get('email');

// Delete a value
$container->delete('email');
```

Basic manipulation of items using array syntax.

```php
<?php

use WP_Forge\Container\Container;

// Create a new instance
$container = new Container();

// Set a value
$container['email'] = 'webmaster@site.com';

// Check if a value exists
$exists = isset( $container['email'] );

// Get a value
$value = $container['email'];

// Delete a value
unset( $container['email'] );
```

Register a factory. Factories return a new class instance every time you fetch them.

```php
<?php

use WP_Forge\Container\Container;

// Create a new instance
$container = new Container();

// Add a factory
$container->set( 'session', $container->factory( function( Container $c ) {
    return new Session( $c->get('session_id') );
} ) );

// Get a factory instance.
$factory = $container->get( 'session' );

// Check if an item is a factory
$isFactory = $container->isFactory( $factory );
```

Register a service. Services return the same class instance every time you fetch them.

```php
<?php

use WP_Forge\Container\Container;

// Create a new instance
$container = new Container();

// Add a service
$container->set( 'session', $container->service( function( Container $c ) {
    return new Session( $c->get('session_id') );
} ) );

// Get a service instance.
$service = $container->get( 'session' );

// Check if an item is a service
$isService = $container->isService( $service );
```

Register a computed value callback. 

```php
<?php

use WP_Forge\Container\Container;

$container = new Container( [
	'first_name'  => 'John',
	'last_name'   => 'Doe',
] );

$container->set( 'full_name', $container->computed( function ( Container $container ) {
	return implode( ' ', array_filter( [
		$container->has( 'first_name' ) ? $container->get( 'first_name' ) : '',
		$container->has( 'last_name' ) ? $container->get( 'last_name' ) : '',
	] ) );
} ) );

$full_name = $container->get( 'full_name' );
```

Extend a previously registered factory or service.

```php
<?php

$container->extend( 'session', function( $instance, Closure $c ) {

    $instance->setShoppingCart( $c->get('shopping_cart') );

    return $instance;
} );

```
