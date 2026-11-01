"""Section `node_apps`: every listening Node process and the dependencies its lock file resolves.

The Node binary is never executed (it may belong to a server user): its version
comes from its path (nvm, n, fnm) or from dpkg for the system package. Process ids
are not kept: they change at each restart and would change the hash for nothing.
package-lock.json is user-controlled data: only name and version are kept.
"""

import os

from discover_agent import util
from discover_agent.collectors import node, sites, techs
from discover_agent.sections.core import Collected, describe
from discover_agent.sections.site_dependencies import read_lock
from discover_agent.sections.tools import run_tool

_NODE_MODULES = "node_modules/"


def _entry(name, version):
    if isinstance(name, str) and techs._PACKAGE_NAME.match(name):
        return {"name": name, "version": techs.clean_version(version)}
    return None


def dependencies(lock):
    """Lockfile v2/v3 `packages` (or v1 `dependencies`) -> sorted unique name/version pairs."""
    found = set()
    packages = lock.get("packages")
    if isinstance(packages, dict):
        for path, info in packages.items():
            if not path or not isinstance(info, dict):
                continue  # "" is the application itself
            _, marker, name = path.rpartition(_NODE_MODULES)
            entry = _entry(name, info.get("version")) if marker else None
            if entry:
                found.add((entry["name"], entry["version"]))
    else:
        stack = [lock.get("dependencies")]
        while stack:  # iterative: a hostile lock may nest deeper than the recursion limit
            level = stack.pop()
            if not isinstance(level, dict):
                continue
            for name, info in level.items():
                if isinstance(info, dict):
                    entry = _entry(name, info.get("version"))
                    if entry:
                        found.add((entry["name"], entry["version"]))
                    stack.append(info.get("dependencies"))
    return [{"name": n, "version": v} for n, v in sorted(found, key=lambda p: (p[0], p[1] or ""))]


def collect(runner=util.run, exe_of=None,
            proc_info=sites.proc_info, read_package=techs.read_package,
            detect_technologies=techs.detect_technologies, reader=util.read_regular_file,
            exists=os.path.lexists):
    errors = {}
    exe_of = exe_of or (lambda pid: node._readlink(f"/proc/{pid}/exe"))
    sockets = node.parse_ss_sockets(run_tool(runner, ["ss", "-Hltnp"]))
    system_version = []

    def dpkg_version():
        if not system_version:
            try:
                output = run_tool(runner, ["dpkg-query", "-W", "-f", "${Version}", "nodejs"])
            except Exception:
                output = ""
            system_version.append(node.parse_dpkg_version(output))
        return system_version[0]

    apps = {}
    for address, port, pid in sockets:
        try:
            exe, deleted = node.split_deleted(exe_of(pid))
            info = proc_info(pid) if node.is_node(exe) else None
            if not info:
                continue
            key = (info["user"], info["cwd"], info["process_manager"])
            app = apps.get(key)
            if app is None:
                name, version = read_package(info["cwd"])
                app = {
                    "name": name,
                    "version": version,
                    "exe": exe,
                    "exe_deleted": deleted,
                    "node_version": node.version_of_exe(exe, deleted, dpkg_version),
                    "listen": [],
                    "user": info["user"],
                    "group": info["group"],
                    "app_dir": info["cwd"],
                    "process_manager": info["process_manager"],
                    "technologies": detect_technologies(info["cwd"]),
                    "dependencies": _lock_dependencies(info["cwd"], reader, exists, errors),
                }
                apps[key] = app
            listen = {"address": address, "port": port}
            if listen not in app["listen"]:
                app["listen"].append(listen)
        except Exception as exc:  # one broken app must not hide the others
            errors[f"node :{port}"] = describe(exc)
    for app in apps.values():
        app["listen"].sort(key=lambda l: (l["port"], l["address"]))
    return Collected({"apps": sorted(apps.values(), key=lambda a: (a["app_dir"] or "", a["user"] or ""))}, errors)


def _lock_dependencies(app_dir, reader, exists, errors):
    if not app_dir:
        return []
    path = os.path.join(app_dir, "package-lock.json")
    status, data = read_lock(path, reader, exists)
    if status == "absent":
        return []
    if status == "error":
        errors[f"package-lock.json {app_dir}"] = data
        return None
    return dependencies(data)
