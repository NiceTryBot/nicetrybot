<?php

namespace NiceTry\Transfer;

use RuntimeException;

/**
 * A transfer that can't be read or imported, with a message for the person importing it. The message
 * never carries the bundle's contents or a token.
 */
class InvalidTransferBundle extends RuntimeException {}
