<?php

namespace WP_Forge\Container;

use Exception;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Class NotFoundException
 *
 * @package WP_Forge\Container
 */
class NotFoundException extends Exception implements NotFoundExceptionInterface {

}
