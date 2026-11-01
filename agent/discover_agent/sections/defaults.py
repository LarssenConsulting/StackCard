"""Built-in allow-lists for the sections (the spec's defaults)."""

SYSCTL = (
    "net.mptcp.enabled",
    "net.ipv4.tcp_migrate_req",
    "kernel.unprivileged_userns_clone",
    "user.max_user_namespaces",
    "kernel.unprivileged_bpf_disabled",
    "kernel.kptr_restrict",
    "kernel.dmesg_restrict",
    "net.ipv4.ip_forward",
    "net.ipv6.conf.all.forwarding",
)

# Always present in `values` (null when absent); every other directive found is reported too.
PHP_INI = (
    "session.upload_progress.enabled",
    "session.serialize_handler",
    "session.save_handler",
    "session.save_path",
    "disable_functions",
    "allow_url_include",
    "allow_url_fopen",
    "open_basedir",
    "expose_php",
    "display_errors",
)

MITIGATION_SERVICES = ("cve98432-filter.service",)

# Reported as path + line + id, never the matching line.
CODE_PATTERNS = (
    {"id": "serialize-handler-iniset", "regex": r"ini_set\(\s*['\"]session\.serialize_handler", "globs": ["*.php"]},
    {"id": "serialize-handler-htaccess", "regex": r"serialize_handler", "globs": [".htaccess", ".user.ini"]},
)

# Apache directives reported with file, line and value (canonical spelling).
APACHE_DIRECTIVES = ("Protocols", "VirtualDocumentRoot", "LimitRequestFieldSize", "AllowOverride")
