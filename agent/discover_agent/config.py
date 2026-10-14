"""Agent configuration, credentials and local state.

agent.conf and credentials are root-only (0600): the token is the server's
identity towards the central API.
"""

import configparser
import io
import json
import os
from dataclasses import dataclass


@dataclass
class Paths:
    etc_dir: str = "/etc/discover-agent"
    state_dir: str = "/var/lib/discover-agent"

    @property
    def conf(self):
        return os.path.join(self.etc_dir, "agent.conf")

    @property
    def credentials(self):
        return os.path.join(self.etc_dir, "credentials")

    @property
    def state(self):
        return os.path.join(self.state_dir, "state.json")


@dataclass
class Config:
    url: str
    token: str
    ca_file: str | None = None
    pin_sha256: str | None = None


def default_paths():
    return Paths()


def _write_private(path, content):
    os.makedirs(os.path.dirname(path), mode=0o700, exist_ok=True)
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "w") as fh:
        fh.write(content)
    os.chmod(path, 0o600)


def save(paths, cfg):
    parser = configparser.ConfigParser()
    parser["central"] = {
        "url": cfg.url,
        "ca_file": cfg.ca_file or "",
        "pin_sha256": cfg.pin_sha256 or "",
    }
    buffer = io.StringIO()
    parser.write(buffer)
    _write_private(paths.conf, buffer.getvalue())
    _write_private(paths.credentials, cfg.token + "\n")


def load(paths):
    """Return the Config, or None when the agent has not been enrolled."""
    if not (os.path.exists(paths.conf) and os.path.exists(paths.credentials)):
        return None
    parser = configparser.ConfigParser()
    parser.read(paths.conf)
    with open(paths.credentials) as fh:
        token = fh.read().strip()
    section = parser["central"]
    return Config(
        url=section.get("url"),
        token=token,
        ca_file=section.get("ca_file") or None,
        pin_sha256=section.get("pin_sha256") or None,
    )


def load_state(paths):
    try:
        with open(paths.state) as fh:
            return json.load(fh)
    except (OSError, ValueError):
        return {}


def save_state(paths, state):
    os.makedirs(paths.state_dir, mode=0o700, exist_ok=True)
    tmp = paths.state + ".tmp"
    with open(tmp, "w") as fh:
        json.dump(state, fh, indent=2)
    os.replace(tmp, paths.state)
