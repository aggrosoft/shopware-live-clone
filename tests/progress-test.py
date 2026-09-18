"""Check binary-safe forwarding and that rsync filenames cannot reach logs."""
import os
from pathlib import Path
import subprocess
import tempfile

script = Path(__file__).resolve().parents[1] / 'scripts/progress.php'
payload = os.urandom(1024 * 1024) + b'\x00SQL_SECRET\r\n'
with tempfile.TemporaryDirectory() as directory:
    count = Path(directory) / 'bytes'
    result = subprocess.run(
        ['php', str(script), 'stream', 'Dump', str(len(payload)), str(count)],
        input=payload, capture_output=True, check=True,
    )
    assert result.stdout == payload
    assert int(count.read_text()) == len(payload)
    assert b'100%' in result.stderr
    assert b'SQL_SECRET' not in result.stderr

result = subprocess.run(
    ['php', str(script), 'rsync', 'Copy'],
    input=b'SECRET_FILENAME\n\r 1,048,576  50% 1.00MB/s 0:00:01\r 2,097,152 100% 1.00MB/s 0:00:00\n',
    capture_output=True, check=True,
)
assert result.stdout == b''
assert b'SECRET' not in result.stderr
assert b'2.0 MiB' in result.stderr and b'100%' in result.stderr
print('Progress: binary stream preserved; byte counts correct; filenames suppressed.')
