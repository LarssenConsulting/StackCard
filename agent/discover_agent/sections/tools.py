"""Helpers shared by the section modules."""

from discover_agent import util


SLOW_TIMEOUT = 25  # for tools that may take a while; the section timeout is 30 s


def run_tool(runner, cmd, timeout=None, **kwargs):
    """Run `cmd`; a missing command is `NotInstalled`, not a failure."""
    if timeout is not None:
        kwargs["timeout"] = timeout
    try:
        return runner(cmd, **kwargs)
    except util.CommandError as exc:
        if str(exc).startswith("command not found"):
            raise util.NotInstalled(cmd[0]) from exc
        raise
