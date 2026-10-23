"""Regenerates tests/Fixtures/segno-reference.json for QrCodeReferenceTest.

Not part of the PHP package or its CI — a one-off, documented way to rebuild the
reference symbols with the independent Python encoder segno (pure Python, no deps):

    # download segno-1.6.6-py3-none-any.whl from https://pypi.org/project/segno/1.6.6/#files
    python3 -c "import zipfile; zipfile.ZipFile('segno-1.6.6-py3-none-any.whl').extractall('segno-pkg')"
    python3 -I tests/Fixtures/generate_segno_reference.py segno-pkg tests/Fixtures/segno-reference.json

Each case stores the SHA-256 of the module matrix (rows of "0"/"1" joined by "\n").
"""
import sys, json, hashlib
sys.path.insert(0, sys.argv[1])
import segno
from segno import encoder, consts

# segno 1.6.6 always appends 8 - (len % 8) zero bits, i.e. a whole 0x00 byte when the
# bit stream already ends on a codeword boundary. ISO/IEC 18004 7.4.10 only pads when
# it does not; use the spec-conformant variant so the references follow the standard.
def _spec_padding_bits(buff, version, length):
    if version not in (consts.VERSION_M1, consts.VERSION_M3) and length % 8:
        buff.extend([0] * (8 - length % 8))
encoder.write_padding_bits = _spec_padding_bits

def digest(qr):
    rows = [''.join('1' if b & 1 else '0' for b in row) for row in qr.matrix]
    return hashlib.sha256('\n'.join(rows).encode()).hexdigest()

def case(data, level, version=None, mask=None, mode=None):
    qr = segno.make(data, error=level, version=version, mask=mask, mode=mode, micro=False, boost_error=False)
    return {'data': data, 'level': level.upper(), 'forcedVersion': version, 'forcedMask': mask,
            'version': qr.version, 'mask': qr.mask, 'mode': qr.mode, 'sha256': digest(qr)}

payloads = {'numeric': '0123456789', 'alphanumeric': 'HELLO WORLD $%*+-./:', 'byte': 'otpauth://totp/Ex:alice?secret=JBSWY3DPEHPK3PXP'}
cases = []
levels = ['L', 'M', 'Q', 'H']
modes = list(payloads)
for v in range(1, 41):
    mode = modes[v % 3]
    level = levels[v % 4]
    data = payloads[mode]
    if v <= 2:
        data = data[:5]  # small versions at high levels have little room
    cases.append(case(data, level.lower(), version=v, mask=v % 8, mode=mode))
for level in levels:
    for mask in range(8):
        cases.append(case('ez-php QR ' + level + str(mask), level.lower(), version=7, mask=mask))
auto_inputs = ['1', '12345678901234567890', 'HELLO WORLD', 'https://example.com/?a=1&b=2',
               'otpauth://totp/Example:alice@example.com?secret=JBSWY3DPEHPK3PXP&issuer=Example',
               'x' * 300, '9' * 1500, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ' * 40, 'ümlaut ✓', 'a' * 2000]
for i, data in enumerate(auto_inputs):
    cases.append(case(data, levels[i % 4].lower()))
json.dump(cases, open(sys.argv[2], 'w'), indent=1, ensure_ascii=False)
print(len(cases), 'cases')
