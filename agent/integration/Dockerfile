FROM ubuntu:24.04
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      apache2 php8.3-fpm mariadb-server nodejs openssl iproute2 python3 ca-certificates debsums nftables \
 && rm -rf /var/lib/apt/lists/*
COPY setup.sh /setup.sh
RUN bash /setup.sh
COPY discover-agent.deb /tmp/discover-agent.deb
RUN dpkg -i /tmp/discover-agent.deb
COPY check.py /check.py
# `collect --full` adds the weekly sections, including a checksum pass (debsums).
CMD ["bash", "-c", "service mariadb start && service php8.3-fpm start && apachectl start \
 && (runuser -u app-api -- sh -c 'cd /srv/api && exec node server.js' &) && sleep 3 \
 && discover-agent collect --full > /tmp/snapshot.json && python3 /check.py /tmp/snapshot.json"]
