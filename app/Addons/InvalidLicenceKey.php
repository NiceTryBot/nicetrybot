<?php

namespace App\Addons;

use InvalidArgumentException;

/**
 * A licence key that can't be accepted: not a key, a signature that doesn't hold, or a key for
 * another add-on. The message says which, in words for the Add-ons page.
 */
class InvalidLicenceKey extends InvalidArgumentException
{
    //
}
