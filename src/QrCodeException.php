<?php

declare(strict_types=1);

namespace EzPhp\QrCode;

/**
 * Class QrCodeException
 *
 * The data does not fit (into the requested version, or into version 40 at
 * the requested error-correction level), an option is out of range, or a
 * renderer is unavailable (PNG without ext-gd).
 *
 * @package EzPhp\QrCode
 */
final class QrCodeException extends \InvalidArgumentException
{
}
