<?php
/**
 * @package PlugixaInventory
 */

declare( strict_types = 1 );

namespace PlugixaInventory\Core;

use Psr\Container\ContainerInterface;
use PlugixaInventory\Core\Exceptions\NotFoundException;
use PlugixaInventory\Core\Exceptions\ContainerException;
use ReflectionClass;
use ReflectionNamedType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A small PSR-11 container with constructor autowiring.
 *
 * Hand-rolled on purpose. A full DI library (PHP-DI, League) would be vendored into
 * vendor/, where it can collide fatally with a different version of the same library
 * vendored by another active plugin — WordPress has one class namespace for all of
 * them. Only the psr/container *interface* is a dependency, which is safe to share.
 */
final class Container implements ContainerInterface {

	/**
	 * Explicit factories, keyed by id.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Resolved shared instances, keyed by id.
	 *
	 * @var array<string, mixed>
	 */
	private array $instances = array();

	/**
	 * Ids currently being resolved, used to detect circular dependencies.
	 *
	 * @var array<string, true>
	 */
	private array $resolving = array();

	/**
	 * Register a factory. The result is memoised, so the factory runs at most once.
	 *
	 * @param string   $id      Service id, conventionally a class or interface name.
	 * @param callable $factory Receives the container, returns the service.
	 */
	public function share( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Register an already-constructed instance.
	 *
	 * @param string $id       Service id.
	 * @param mixed  $instance The service.
	 */
	public function instance( string $id, mixed $instance ): void {
		$this->instances[ $id ] = $instance;
	}

	/**
	 * @param string $id Service id.
	 */
	public function has( string $id ): bool {
		return isset( $this->instances[ $id ] )
			|| isset( $this->factories[ $id ] )
			|| class_exists( $id );
	}

	/**
	 * Resolve a service, autowiring it from its constructor signature if it has no
	 * registered factory.
	 *
	 * @param string $id Service id.
	 *
	 * @throws NotFoundException  If the id cannot be resolved to anything.
	 * @throws ContainerException If the id is resolvable but cannot be constructed.
	 */
	public function get( string $id ): mixed {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( isset( $this->resolving[ $id ] ) ) {
			// Escaped because WordPress's fatal-error handler can render an exception
			// message into a page. These particular ids are class names from our own code
			// rather than anything a request supplies, so this is hygiene rather than a
			// live hole — but "the input happens to be safe today" is not a property that
			// survives contact with the next caller.
			throw new ContainerException(
				esc_html( sprintf( 'Circular dependency detected while resolving "%s".', $id ) )
			);
		}

		$this->resolving[ $id ] = true;

		try {
			if ( isset( $this->factories[ $id ] ) ) {
				$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );
			} elseif ( class_exists( $id ) ) {
				$this->instances[ $id ] = $this->autowire( $id );
			} else {
				throw new NotFoundException( esc_html( sprintf( 'Service "%s" is not registered.', $id ) ) );
			}
		} finally {
			unset( $this->resolving[ $id ] );
		}

		return $this->instances[ $id ];
	}

	/**
	 * Construct a class by resolving each constructor parameter from the container.
	 *
	 * Scalar parameters must have a default value; there is no parameter-binding
	 * syntax on purpose, because needing one is a sign the class wants a factory.
	 *
	 * @param class-string $class_name Class to construct.
	 *
	 * @throws ContainerException If a parameter cannot be resolved.
	 */
	private function autowire( string $class_name ): object {
		$reflection = new ReflectionClass( $class_name );

		if ( ! $reflection->isInstantiable() ) {
			throw new ContainerException( esc_html( sprintf( '"%s" is not instantiable.', $class_name ) ) );
		}

		$constructor = $reflection->getConstructor();
		if ( null === $constructor ) {
			return new $class_name();
		}

		$args = array();
		foreach ( $constructor->getParameters() as $param ) {
			$type = $param->getType();

			if ( $type instanceof ReflectionNamedType && ! $type->isBuiltin() ) {
				$args[] = $this->get( $type->getName() );
				continue;
			}

			if ( $param->isDefaultValueAvailable() ) {
				$args[] = $param->getDefaultValue();
				continue;
			}

			throw new ContainerException(
				esc_html(
					sprintf(
						'Cannot autowire parameter "$%s" of "%s": register a factory for it.',
						$param->getName(),
						$class_name
					)
				)
			);
		}

		return $reflection->newInstanceArgs( $args );
	}
}
