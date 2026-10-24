#!/usr/bin/env python3
"""Build the files of a GitHub release (see .github/workflows/ci.yml), under <root>/agent/.

    install.sh                            what `curl ... | sudo sh` runs
    discover-agent_<version>_all.deb      kept for pinning or rollback
    discover-agent_latest_all.deb         what install.sh downloads
    discover-agent_latest_all.deb.sha256  checked by install.sh before installing

Published as a GitHub release by .github/workflows/ci.yml when an agent-v<version> tag is pushed.

Usage: python3 agent/packaging/build_release.py [root]   (default: agent/dist/release)
"""

import hashlib
import os
import shutil
import sys

import build_deb

PACKAGING = os.path.dirname(os.path.abspath(__file__))
LATEST = "discover-agent_latest_all.deb"


def build(root=None):
    root = root or os.path.join(build_deb.AGENT, "dist", "release")
    out = os.path.join(root, "agent")
    shutil.rmtree(out, ignore_errors=True)
    os.makedirs(out)

    deb = build_deb.build(out)
    latest = os.path.join(out, LATEST)
    shutil.copyfile(deb, latest)
    with open(latest, "rb") as fh:
        digest = hashlib.sha256(fh.read()).hexdigest()
    with open(latest + ".sha256", "w") as fh:
        fh.write(f"{digest}  {LATEST}\n")

    script = os.path.join(out, "install.sh")
    shutil.copyfile(os.path.join(PACKAGING, "install.sh"), script)
    os.chmod(script, 0o755)
    return out


if __name__ == "__main__":
    print(build(sys.argv[1] if len(sys.argv) > 1 else None))
