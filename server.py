#!/usr/bin/env python3
"""
Nimbus SaaS — local development launcher.

Usage:
    python server.py                # serve on http://127.0.0.1:8000
    python server.py --port 9000
    python server.py --host 0.0.0.0 --port 8000

This is a thin, zero-dependency wrapper. The application itself is raw
object-oriented PHP (PDO + SQLite); this script simply locates the PHP CLI
and boots PHP's built-in web server with our front-controller router.

Why a Python launcher? A single, memorable entry point for local dev that
does not depend on how PHP happens to be installed — it finds php on PATH,
verifies the pdo_sqlite extension is present, prints the seeded demo
credentials, and hands off.
"""

import argparse
import os
import shutil
import subprocess
import sys

ROOT = os.path.dirname(os.path.abspath(__file__))
ROUTER = os.path.join(ROOT, "router.php")


def find_php() -> str:
    php = shutil.which("php")
    if not php:
        sys.exit(
            "ERROR: PHP CLI not found on PATH.\n"
            "Install PHP 8.1+ with the pdo_sqlite extension, then re-run.\n"
            "  Debian/Ubuntu: sudo apt-get install php-cli php-sqlite3\n"
            "  macOS (brew):  brew install php"
        )
    return php


def check_pdo_sqlite(php: str) -> None:
    probe = "exit(extension_loaded('pdo_sqlite') ? 0 : 1);"
    result = subprocess.run([php, "-r", probe])
    if result.returncode != 0:
        sys.exit(
            "ERROR: the PHP 'pdo_sqlite' extension is not enabled.\n"
            "Enable it in your php.ini (extension=pdo_sqlite) and re-run."
        )


def banner(host: str, port: int) -> None:
    url = f"http://{host}:{port}"
    line = "=" * 60
    print(line)
    print("  Nimbus SaaS — local dev server")
    print(line)
    print(f"  Landing page ....... {url}/")
    print(f"  Register ........... {url}/register")
    print(f"  Login .............. {url}/login")
    print(f"  Client app ......... {url}/app")
    print(f"  Admin console ...... {url}/admin")
    print(line)
    print("  Seeded demo accounts (created on first run):")
    print("    Operator : admin@nimbus.test  / Admin1234")
    print("    Acme (Pro)   : owner@acme.test   / Acme1234")
    print("    Globex (Free): owner@globex.test / Globex1234")
    print(line)
    print("  Press Ctrl+C to stop.")
    print(line, flush=True)


def main() -> None:
    parser = argparse.ArgumentParser(description="Launch the Nimbus SaaS dev server.")
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=8000)
    args = parser.parse_args()

    php = find_php()
    check_pdo_sqlite(php)

    os.makedirs(os.path.join(ROOT, "storage"), exist_ok=True)
    banner(args.host, args.port)

    # Docroot is public/ so that when router.php returns false for a real
    # asset, the built-in server streams it from the right place.
    docroot = os.path.join(ROOT, "public")
    cmd = [php, "-S", f"{args.host}:{args.port}", "-t", docroot, ROUTER]
    try:
        # Hand the terminal over to PHP; forward Ctrl+C cleanly.
        sys.exit(subprocess.call(cmd, cwd=ROOT))
    except KeyboardInterrupt:
        print("\nShutting down.")
        sys.exit(0)


if __name__ == "__main__":
    main()
