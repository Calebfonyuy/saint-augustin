#!/usr/bin/env python3
"""
Generate and inject secrets into .env files for SaintAugustin.

Secrets generated:
  - POSTGRES_PASSWORD  (shared: root .env + services/auth/.env DB_PASSWORD)
  - MINIO_ROOT_PASSWORD
  - JWT_SECRET         (shared: root .env + services/auth/.env)
  - APP_KEY            (Laravel base64-encoded 32-byte key, services/auth/.env only)

Google OAuth credentials are intentionally left untouched.

Usage:
  python generate_passwords.py          # dry-run (print changes only)
  python generate_passwords.py --write  # apply changes to disk
"""

import argparse
import base64
import os
import re
import secrets
import string
from pathlib import Path

ROOT = Path(__file__).parent
ROOT_ENV = ROOT / ".env"
AUTH_ENV = ROOT / "services" / "auth" / ".env"

PASSWORD_ALPHABET = string.ascii_letters + string.digits + "!@#$%^&*"
PASSWORD_LENGTH = 32


def generate_password(length: int = PASSWORD_LENGTH) -> str:
    return "".join(secrets.choice(PASSWORD_ALPHABET) for _ in range(length))


def generate_jwt_secret(length: int = 64) -> str:
    """URL-safe base64 token, long enough for HS256/HS512."""
    return secrets.token_urlsafe(length)


def generate_laravel_app_key() -> str:
    """Laravel expects base64:<base64-encoded 32 random bytes>."""
    raw = secrets.token_bytes(32)
    return "base64:" + base64.b64encode(raw).decode()


def set_env_value(content: str, key: str, value: str) -> str:
    """
    Replace the value of `key` in an .env file content string.
    Matches lines like:  KEY=anything  (with optional surrounding whitespace).
    If the key is not found, appends it at the end.
    """
    pattern = re.compile(
        r"^(" + re.escape(key) + r"\s*=)(.*)$",
        re.MULTILINE,
    )
    if pattern.search(content):
        return pattern.sub(r"\g<1>" + value, content)
    # Key missing — append it
    return content.rstrip("\n") + f"\n{key}={value}\n"


def load(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def save(path: Path, content: str) -> None:
    path.write_text(content, encoding="utf-8")


def apply_secrets(write: bool) -> None:
    # ── Generate ──────────────────────────────────────────────────────────────
    postgres_password = generate_password()
    minio_password = generate_password()
    jwt_secret = generate_jwt_secret()
    laravel_app_key = generate_laravel_app_key()

    # ── Root .env ─────────────────────────────────────────────────────────────
    root_content = load(ROOT_ENV)
    root_content = set_env_value(root_content, "POSTGRES_PASSWORD", postgres_password)
    root_content = set_env_value(root_content, "MINIO_ROOT_PASSWORD", minio_password)
    root_content = set_env_value(root_content, "JWT_SECRET", jwt_secret)

    # ── services/auth/.env ────────────────────────────────────────────────────
    auth_content = load(AUTH_ENV)
    auth_content = set_env_value(auth_content, "APP_KEY", laravel_app_key)
    auth_content = set_env_value(auth_content, "DB_PASSWORD", postgres_password)
    auth_content = set_env_value(auth_content, "JWT_SECRET", jwt_secret)

    # ── Output ────────────────────────────────────────────────────────────────
    print("Generated secrets:")
    print(f"  POSTGRES_PASSWORD   = {postgres_password}")
    print(f"  MINIO_ROOT_PASSWORD = {minio_password}")
    print(f"  JWT_SECRET          = {jwt_secret}")
    print(f"  APP_KEY             = {laravel_app_key}")
    print()

    if write:
        save(ROOT_ENV, root_content)
        save(AUTH_ENV, auth_content)
        print(f"Written: {ROOT_ENV}")
        print(f"Written: {AUTH_ENV}")
    else:
        print("Dry-run mode — pass --write to apply changes.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--write",
        action="store_true",
        help="Write secrets to .env files (default: dry-run, print only)",
    )
    args = parser.parse_args()
    apply_secrets(write=args.write)
