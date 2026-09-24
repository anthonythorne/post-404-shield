<?php
/**
 * A database read that failed while building a list (a deadlock, a lost
 * connection): the list stays as it was, and the save or job that needed it
 * is refused with a retry — a failed read says nothing about the config.
 *
 * Its own class, so only a read failure is retried: any other exception
 * (SPL's UnexpectedValueException, OutOfRangeException … are
 * RuntimeExceptions too) is a bug, and refuses instead of looping.
 *
 * File Path: wp-content/mu-plugins/post-404-shield/src/php/Library/ReadFailure.php
 *
 * @package Post404Shield\Library
 */

declare(strict_types=1);

namespace Post404Shield\Library;

/**
 * A failed database read.
 */
class ReadFailure extends \RuntimeException {
}
