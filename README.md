# ez-php/qrcode

Dependency-free QR code encoder for PHP 8.5: versions 1–40, error-correction levels L/M/Q/H, numeric/alphanumeric/byte mode. Renders SVG, or PNG via ext-gd.

## Installation

```bash
composer require ez-php/qrcode
```

## Usage

```php
use EzPhp\QrCode\Encoder;
use EzPhp\QrCode\ErrorCorrectionLevel;
use EzPhp\QrCode\Renderer\PngRenderer;
use EzPhp\QrCode\Renderer\SvgRenderer;

$qr = (new Encoder())->encode('https://example.com/invite/42', ErrorCorrectionLevel::M);

$svg = (new SvgRenderer(size: 240))->render($qr);   // '<svg …>…</svg>'
$png = (new PngRenderer(scale: 8))->render($qr);    // PNG bytes, needs ext-gd
```

Serving it:

```php
return (new Response($svg))->withHeader('Content-Type', 'image/svg+xml');
```

### TOTP enrolment with ez-php/two-factor

```php
$uri = $twoFactor->getQrCodeUrl('MyApp', $user->email, $secret);   // otpauth://totp/...
$svg = (new SvgRenderer(size: 200))->render((new Encoder())->encode($uri));
```

## Options

`Encoder::encode($data, $level = M, $version = null, $mask = null, $mode = null)`:

- **Level:** `L` ≈ 7 %, `M` ≈ 15 %, `Q` ≈ 25 %, `H` ≈ 30 % recoverable damage. Kept as given, never raised automatically.
- **Version:** `null` picks the smallest symbol that fits; 1–40 forces one. Data that doesn't fit throws `QrCodeException`.
- **Mask:** `null` picks the lowest-penalty mask (ISO/IEC 18004 7.8); 0–7 forces one.
- **Mode:** `null` picks the densest mode the whole input fits: numeric (digits), alphanumeric (`0-9 A-Z $%*+-./:` and space), else byte.

The result `QrCode` exposes `modules` (rows of booleans, no quiet zone), `version`, `level`, `mode`, `mask`, `size()` and `isDark($x, $y)`. Use these to render the code any other way.

Renderers:

- `SvgRenderer(?int $size, int $quietZone = 4, string $foreground, string $background)`. The background may be `''` for a transparent SVG.
- `PngRenderer(int $scale = 4, int $quietZone = 4, string $foreground = '#000000', string $background = '#ffffff')`.

## Correctness

The test suite compares 82 symbols bit for bit with the independent Python encoder [segno](https://github.com/heuer/segno), covering every version, level, mask and mode.

## Not included

Decoding, logos/styling, Micro QR, Kanji/mixed-mode segments, ECI.

## License

MIT
