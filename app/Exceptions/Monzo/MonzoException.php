<?php

namespace App\Exceptions\Monzo;

use RuntimeException;

/**
 * Base for every failure originating from the Monzo API.
 */
class MonzoException extends RuntimeException {}
