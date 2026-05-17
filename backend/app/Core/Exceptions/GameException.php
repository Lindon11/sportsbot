<?php

namespace App\Core\Exceptions;

/**
 * Thrown by game services for user-facing domain errors.
 *
 * Messages from this exception class are safe to return directly to the API
 * client (e.g. "Insufficient funds", "Item not found in inventory").
 *
 * Contrast with generic \Exception / \RuntimeException, whose messages may
 * contain internal DB table names, file paths, or stack details — those should
 * be logged server-side and replaced with a generic client message.
 */
class GameException extends \RuntimeException {}
