#!/usr/bin/env python3
"""Run the PHP worker on the Mac with agy login and the Docker MariaDB."""
import argparse
import fcntl
import json
import os
import re
from pathlib import Path
import shutil
import signal
import subprocess
import sys

SRC = Path(__file__).resolve().parents[1]
ROOT = SRC.parent
DATA = SRC / "var"
PID = DATA / "local-worker.pid"
MONITOR_PID = DATA / "local-monitor.pid"


def running_pid(path=PID, command="worker"):
    if not path.exists():
        return None
    pid = int(path.read_text().strip())
    result = subprocess.run(["ps", "-p", str(pid), "-o", "command="], capture_output=True, text=True)
    return pid if str(SRC / "bin/console.php") + " " + command in result.stdout else None


def environment(args):
    config = subprocess.run(
        ["docker", "compose", "-f", str(ROOT / "docker-compose.yaml"),
         "-f", str(ROOT / "docker-compose.local.yaml"), "config", "--format", "json"],
        cwd=ROOT, check=True, capture_output=True, text=True,
    )
    services = json.loads(config.stdout)["services"]
    runtime = services["php"]["environment"]
    database = services["mariadb"]
    port = next(p["published"] for p in database["ports"] if p["target"] == 3306)
    name = database["environment"]["MARIADB_DATABASE"]
    env = os.environ.copy()
    env.update({key: str(value) for key, value in runtime.items() if value is not None})
    env.update({
        "B24_DATABASE_DSN": f"mysql:host=127.0.0.1;port={port};dbname={name};charset=utf8mb4",
        "B24_DATA_DIR": str(DATA),
        "B24_PROFILE_FILE": str(SRC / "config/portal.json"),
        "B24_AGENT_PROVIDER": "agy",
        "B24_AGY_BIN": shutil.which("agy") or "agy",
        "B24_AUTOMATION_ENABLED": "true",
        "B24_SEND_ENABLED": "true" if args.send else "false",
        "B24_POLL_INTERVAL": str(args.poll_interval),
        "B24_WORKER_MONITOR_ENABLED": "false",
        "B24_AGY_EFFORT": args.effort,
    })
    # Docker paths in compose do not apply to this host process.
    for key in ["B24_LOG_DIR", "B24_SECRETS_FILE"]:
        if env.get(key, "").startswith("/var/www/html/"):
            env.pop(key)
    if args.model:
        env["B24_AGY_MODEL"] = args.model
    return env


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=["start", "stop", "status", "prime", "check"])
    parser.add_argument("--model")
    parser.add_argument("--effort", choices=["low", "medium", "high"], default="low")
    parser.add_argument("--poll-interval", type=int, choices=range(1, 301), default=5, metavar="SECONDS")
    parser.add_argument("--send", action="store_true", help="Enable queued Bitrix24 writes")
    args = parser.parse_args()
    preset = re.search(r"-(low|medium|high|xhigh|max)$", args.model or "")
    if preset and preset.group(1) != args.effort:
        parser.error("The model preset and --effort must match (e.g. gemini-3.8-flash-low with --effort low)")
    DATA.mkdir(mode=0o700, parents=True, exist_ok=True)
    with (DATA / "local-worker-control.lock").open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        pid = running_pid()
        monitor_pid = running_pid(MONITOR_PID, "monitor")
        if args.command == "status":
            print(json.dumps({"running": bool(pid), "pid": pid, "monitor_running": bool(monitor_pid), "monitor_pid": monitor_pid}))
            return
        if args.command == "stop":
            if pid or monitor_pid:
                for process_id in [pid, monitor_pid]:
                    if process_id:
                        os.kill(process_id, signal.SIGTERM)
                print("Stop requested; current operation may finish first.")
            else:
                print("Worker is not running.")
            return
        if args.command == "start" and (pid or monitor_pid):
            raise RuntimeError("Worker or monitor already running; stop them before changing settings")
        env = environment(args)
        php = shutil.which("php")
        if not php or not Path(env["B24_AGY_BIN"]).is_file():
            raise RuntimeError("Host php and agy executables are required")
        console = [php, str(SRC / "bin/console.php")]
        if args.command in ["prime", "check"]:
            cmd = ["monitor", "--once"] if args.command == "prime" else ["doctor"]
            result = subprocess.run(console + cmd, cwd=SRC, env=env, capture_output=True, text=True)
            if result.returncode:
                raise RuntimeError("Worker preflight failed; check database access and configuration")
            if args.command == "check":
                report = json.loads(result.stdout)
                print(json.dumps({k: report.get(k) for k in ["database", "channels", "agent_configured", "automation_enabled", "send_enabled", "monitor", "worker"]}, ensure_ascii=False))
            else:
                print("Monitor completed; inspect check for errors before enabling sends.")
            return
        if not args.model:
            raise RuntimeError("Pin the chosen model with --model before starting")
        log = DATA / "logs/local-worker.log"
        log.parent.mkdir(mode=0o700, exist_ok=True)
        with os.fdopen(os.open(log, os.O_WRONLY | os.O_APPEND | os.O_CREAT, 0o600), "ab") as output:
            monitor = subprocess.Popen(console + ["monitor"], cwd=SRC, env=env, stdin=subprocess.DEVNULL,
                                       stdout=output, stderr=output, start_new_session=True)
            try:
                process = subprocess.Popen(console + ["worker"], cwd=SRC, env=env, stdin=subprocess.DEVNULL,
                                           stdout=output, stderr=output, start_new_session=True)
            except Exception:
                monitor.terminate()
                raise
        PID.write_text(str(process.pid))
        PID.chmod(0o600)
        MONITOR_PID.write_text(str(monitor.pid))
        MONITOR_PID.chmod(0o600)
        print(json.dumps({"started": True, "pid": process.pid, "monitor_pid": monitor.pid, "model": args.model,
                          "effort": args.effort, "poll_interval": args.poll_interval, "send": args.send}))


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        # Never echo compose config or database credentials on failure.
        print(str(error) if not isinstance(error, subprocess.CalledProcessError) else "Docker Compose configuration failed", file=sys.stderr)
        sys.exit(1)
