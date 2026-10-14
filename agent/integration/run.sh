#!/bin/sh
# End-to-end check of the agent on a real Ubuntu 24.04 (needs Docker).
# Usage: sh agent/integration/run.sh
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
deb=$(python3 "$HERE/../packaging/build_deb.py" "$HERE/.build")
cp "$deb" "$HERE/discover-agent.deb"
trap 'rm -rf "$HERE/.build" "$HERE/discover-agent.deb"' EXIT

docker build -t discover-agent-it "$HERE"
docker run --rm discover-agent-it
