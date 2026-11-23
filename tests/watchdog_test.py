"""Offline checks: no access to the host's real services or credentials."""
import contextlib
import io
from pathlib import Path
import runpy
import subprocess
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[2] / 'docker/server/watchdog.py'


class WatchdogTest(unittest.TestCase):
    def run_watchdog(self, active=True, started=0, failed_role=None):
        restarted = []

        def command(args, **kwargs):
            if args[:2] == ['systemctl', 'show']:
                state = 'active' if active else 'inactive'
                return subprocess.CompletedProcess(args, 0, f'ActiveState={state}\nActiveEnterTimestampMonotonic={started}\n')
            if args[:2] == ['systemctl', 'restart']:
                restarted.append(args[2])
                return subprocess.CompletedProcess(args, 0)
            if args[0] == 'runuser':
                return subprocess.CompletedProcess(args, int(args[-1] == failed_role))
            raise AssertionError('Unexpected command')

        with patch.object(Path, 'read_text', return_value='DB_PASSWORD=test-only\n'), \
             patch('subprocess.run', side_effect=command), \
             patch('time.monotonic', return_value=1000), \
             contextlib.redirect_stdout(io.StringIO()):
            runpy.run_path(str(SCRIPT))
        return restarted

    def test_manual_stop_is_respected(self):
        self.assertEqual(self.run_watchdog(active=False, failed_role='worker'), [])

    def test_startup_grace_is_respected(self):
        self.assertEqual(self.run_watchdog(started=950_000_000, failed_role='worker'), [])

    def test_healthy_processes_are_kept(self):
        self.assertEqual(self.run_watchdog(), [])

    def test_only_stale_process_is_restarted(self):
        self.assertEqual(self.run_watchdog(failed_role='worker'), ['mcp-bitrix24@worker.service'])


if __name__ == '__main__':
    unittest.main()
