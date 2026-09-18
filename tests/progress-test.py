"""Check rsync log redaction, heartbeat cleanup and transfer-error handling."""
from pathlib import Path
import subprocess
import tempfile

script = Path(__file__).resolve().parents[1] / 'scripts/progress.php'
result = subprocess.run(
    ['php', str(script), 'rsync', 'Copy'],
    input=b'SECRET_FILENAME\n\r 1,048,576  50% 1.00MB/s 0:00:01\r 2,097,152 100% 1.00MB/s 0:00:00\n',
    capture_output=True, check=True,
)
assert result.stdout == b''
assert b'SECRET' not in result.stderr
assert b'2.0 MiB' in result.stderr and b'100%' in result.stderr
print('Rsync progress: byte counts correct; filenames suppressed.')

# Rapid heartbeat start/stop must never trigger the caller's EXIT cleanup early.
with tempfile.TemporaryDirectory() as directory:
    marker = Path(directory) / 'cleanup'
    subprocess.run(['bash', '-c', r'''
set -euo pipefail
source "$1"
trap 'echo cleanup >> "$2"' EXIT
for ((i=0; i<100; i++)); do
    clone_progress_start test
    clone_progress_done
    test ! -e "$2"
done
''', 'test', str(script.with_suffix('.sh')), str(marker)], capture_output=True, check=True, timeout=30)
    assert marker.read_text() == 'cleanup\n'

checker = script.with_name('check-rsync-error.php')
with tempfile.TemporaryDirectory() as directory:
    log = Path(directory) / 'rsync.log'
    warning = 'symlink has no referent: "missing"\n'
    summary = 'rsync error: some files/attrs were not transferred (see previous errors) (code 23) at main.c(1338) [sender=3.2.7]\n'
    for status, content, expected in [
        (23, warning + summary, 0),
        (23, warning + 'rsync: Permission denied (13)\n' + summary, 1),
        (23, summary, 1),
        (12, warning, 1),
    ]:
        log.write_text(content)
        result = subprocess.run(['php', str(checker), str(status), str(log)], capture_output=True)
        assert result.returncode == expected, result.stderr
print('Heartbeat cleanup and rsync error classification: OK')
