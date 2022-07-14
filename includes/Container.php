<?php

namespace WP_Forge\Container;

use ArrayAccess;
use Closure;
use Countable;
use Iterator;
use Psr\Container\ContainerInterface;
use SplObjectStorage;

/**
 * Class Container
 *
 * @package WP_Forge\Container
 */
class Container implements ArrayAccess, ContainerInterface, Countable, Iterator {

	/**
	 * Entry storage.
	 *
	 * @var array
	 */
	protected $entries = [];

	/**
	 * Class instance storage.
	 *
	 * @var array
	 */
	protected $instances = [];

	/**
	 * Factory closure storage.
	 *
	 * @var SplObjectStorage
	 */
	protected $factories;

	/**
	 * Function storage.
	 *
	 * @var SplObjectStorage
	 */
	protected $functions;

	/**
	 * Service closure storage.
	 *
	 * @var SplObjectStorage
	 */
	protected $services;

	/**
	 * Internal pointer used for iteration.
	 *
	 * @var int
	 */
	protected $pointer = 0;

	/**
	 * Container constructor.
	 *
	 * @param array $entries Initial entries to store in the container.
	 */
	public function __construct( array $entries = [] ) {
		$this->reset();
		$this->entries = $entries;
	}

	/**
	 * Reset everything.
	 *
	 * @return $this
	 */
	public function reset() {
		$this->entries   = [];
		$this->instances = [];
		$this->factories = new SplObjectStorage();
		$this->functions = new SplObjectStorage();
		$this->services  = new SplObjectStorage();

		return $this;
	}

	/**
	 * Returns true if the container can return an entry for the given identifier.
	 * Returns false otherwise.
	 *
	 * `has($id)` returning true does not mean that `get($id)` will not throw an exception.
	 * It does however mean that `get($id)` will not throw a `NotFoundExceptionInterface`.
	 *
	 * @param string $id Identifier of the entry to look for.
	 *
	 * @return bool
	 */
	public function has( string $id ) {
		return array_key_exists( $id, $this->entries );
	}

	/**
	 * Get a raw value.
	 *
	 * @param string $id Identifier of the entry to look for.
	 *
	 * @return mixed Entry.
	 *
	 * @throws NotFoundException
	 */
	public function raw( string $id ) {
		if ( ! $this->has( $id ) ) {
			throw new NotFoundException( sprintf( 'No entry was found for "%s" identifier.', $id ) );
		}

		return $this->entries[ $id ];
	}

	/**
	 * Finds an entry of the container by its identifier and returns it.
	 *
	 * @param string $id Identifier of the entry to look for.
	 *
	 * @return mixed Entry.
	 *
	 * @throws NotFoundException  No entry was found for **this** identifier.
	 */
	public function get( string $id ) {
		// Return class instance, if available.
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		// Get raw value.
		$value = $this->raw( $id );

		// If this is a factory, return a new instance.
		if ( $this->isFactory( $value ) ) {
			return $value( $this );
		}

		// If this is a service, return a single instance.
		if ( $this->isService( $value ) ) {
			$this->instances[ $id ] = $value( $this );

			return $this->instances[ $id ];
		}

		// If this is a computed value, compute and return the value.
		if ( $this->isComputed( $value ) ) {
			return $value( $this );
		}

		return $value;
	}

	/**
	 * Set an array value by ID.
	 *
	 * @param string $id    The entry identifier.
	 * @param mixed  $value The entry value.
	 *
	 * @return $this
	 */
	public function set( string $id, $value ) {
		$this->entries[ $id ] = $value;

		return $this;
	}

	/**
	 * Delete an entry.
	 *
	 * @param string $id Identifier of the entry to look for.
	 *
	 * @return $this
	 *
	 * @throws NotFoundException
	 */
	public function delete( string $id ) {
		if ( $this->has( $id ) ) {
			$value = $this->get( $id );
			if ( $this->isFactory( $value ) ) {
				$this->factories->detach( $value );
			} else if ( $this->isService( $value ) ) {
				$this->services->detach( $value );
			} else if ( $this->isComputed( $value ) ) {
				$this->functions->detach( $value );
			}
			unset( $this->entries[ $id ], $this->instances[ $id ] );
		}

		return $this;
	}

	/**
	 * Remove an instance.
	 *
	 * @param string $id Identifier of the instance to look for.
	 *
	 * @return $this
	 */
	public function deleteInstance( string $id ) {
		unset( $this->instances[ $id ] );

		return $this;
	}

	/**
	 * Remove all instances.
	 *
	 * @return $this
	 */
	public function deleteAllInstances() {
		$this->instances = [];

		return $this;
	}

	/**
	 * Extend a factory or service by creating a closure that will manipulate the instantiated instance.
	 *
	 * @param string  $id
	 * @param Closure $closure
	 *
	 * @return Closure
	 *
	 * @throws ContainerException
	 * @throws NotFoundException
	 */
	public function extend( string $id, Closure $closure ) {

		// Get the existing raw value
		$value = $this->raw( $id );

		// If the value isn't a factory or service, throw an exception.
		if ( ! $this->isService( $value ) && ! $this->isFactory( $value ) && ! $this->isComputed( $value ) ) {
			throw new ContainerException( sprintf( 'Identifier "%s" does not contain an object definition.', $id ) );
		}

		// Create a new closure that extends the existing one.
		$extended = function ( Container $container ) use ( $closure, $value ) {
			return $closure( $value( $container ), $container );
		};

		if ( $this->isFactory( $value ) ) {

			// Replace factory object.
			$this->factories->detach( $value );
			$this->factories->attach( $extended );

		} else if ( $this->isService( $value ) ) {

			// Replace service object.
			$this->services->detach( $value );
			$this->services->attach( $extended );

		} else if ( $this->isComputed( $value ) ) {

			// Replace function.
			$this->functions->detach( $value );
			$this->functions->attach( $extended );

		}

		// Replace object in entries array.
		$this->entries[ $id ] = $extended;

		return $extended;
	}

	/**
	 * Marks a callable as a factory.
	 *
	 * @param Closure $closure A closure that returns a new class instance.
	 *
	 * @return Closure
	 */
	public function factory( Closure $closure ) {
		$this->factories->attach( $closure );

		return $closure;
	}

	/**
	 * Checks if a value is a factory.
	 *
	 * @param mixed $value Value to check.
	 *
	 * @return bool
	 */
	public function isFactory( $value ) {
		return is_object( $value ) && isset( $this->factories[ $value ] );
	}

	/**
	 * Marks a callable as a computed value.
	 *
	 * @param Closure $closure A closure that returns a new instance (only called once).
	 *
	 * @return Closure
	 */
	public function computed( Closure $closure ) {
		$this->functions->attach( $closure );

		return $closure;
	}

	/**
	 * Checks if a value is a service.
	 *
	 * @param mixed $value Value to check.
	 *
	 * @return bool
	 */
	public function isComputed( $value ) {
		return is_object( $value ) && isset( $this->functions[ $value ] );
	}

	/**
	 * Marks a callable as a service.
	 *
	 * @param Closure $closure A closure that returns a new instance (only called once).
	 *
	 * @return Closure
	 */
	public function service( Closure $closure ) {
		$this->services->attach( $closure );

		return $closure;
	}

	/**
	 * Checks if a value is a service.
	 *
	 * @param mixed $value Value to check.
	 *
	 * @return bool
	 */
	public function isService( $value ) {
		return is_object( $value ) && isset( $this->services[ $value ] );
	}

	/**
	 * Get all array keys.
	 *
	 * @return array
	 */
	public function keys() {
		return array_keys( $this->entries );
	}

	/**
	 * Return the current element.
	 *
	 * Method implements Iterator.
	 *
	 * @return mixed Can return any type.
	 *
	 * @throws NotFoundException  No entry was found for **this** identifier.
	 */
	public function current() {
		return $this->offsetGet( $this->key() );
	}

	/**
	 * Move forward to next element
	 *
	 * Method implements Iterator.
	 *
	 * @return void Any returned value is ignored.
	 */
	public function next() {
		++ $this->pointer;
	}

	/**
	 * Return the key of the current element
	 *
	 * Method implements Iterator.
	 *
	 * @return string The entry identifier for the current entry.
	 */
	public function key() {
		return $this->keys()[ $this->pointer ];
	}

	/**
	 * Checks if current position is valid
	 *
	 * Method implements Iterator.
	 *
	 * @return bool The return value will be cast to boolean and then evaluated.
	 */
	public function valid() {
		return isset( $this->keys()[ $this->pointer ] );
	}

	/**
	 * Rewind the Iterator to the first element
	 *
	 * Method implements Iterator.
	 *
	 * @return void Any returned value is ignored.
	 */
	public function rewind() {
		$this->pointer = 0;
	}

	/**
	 * Whether an offset exists.
	 *
	 * Method implements ArrayAccess.
	 *
	 * @param mixed $offset An offset to check for.
	 *
	 * @return bool True on success or false on failure.
	 */
	public function offsetExists( $offset ) {
		return $this->has( $offset );
	}

	/**
	 * Offset to retrieve.
	 *
	 * Method implements ArrayAccess.
	 *
	 * @param mixed $offset The offset to retrieve.
	 *
	 * @return mixed Can return all value types.
	 *
	 * @throws NotFoundException  No entry was found for **this** identifier.
	 */
	public function offsetGet( $offset ) {
		return $this->get( $offset );
	}

	/**
	 * Offset to set.
	 *
	 * Method implements ArrayAccess.
	 *
	 * @param mixed $offset The offset to assign the value to.
	 * @param mixed $value  The value to set.
	 *
	 * @return void
	 */
	public function offsetSet( $offset, $value ) {
		$this->set( $offset, $value );
	}

	/**
	 * Offset to unset.
	 *
	 * Method implements ArrayAccess.
	 *
	 * @param mixed $offset The offset to unset.
	 *
	 * @return void
	 *
	 * @throws NotFoundException
	 */
	public function offsetUnset( $offset ) {
		$this->delete( $offset );
	}

	/**
	 * Get the number of entries.
	 *
	 * Method implements Countable.
	 *
	 * @return int
	 */
	public function count() {
		return count( $this->entries );
	}
}
