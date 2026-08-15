#!/usr/bin/env bash
# 心悦搜索一键自启脚本（环境重启后运行此脚本即可恢复服务）
# 用法：bash /workspace/scripts/start_all.sh
#
# 操作：
#   1. 若未安装 mariadb-server，则 apt 安装
#   2. 若 /var/lib/mysql 系统表未初始化，则 mariadb-install-db 初始化
#   3. 启动 MariaDB daemon 到后台（disown，不跟随终端退出）
#   4. 创建 xinyue_search 数据库、xinyue@127.0.0.1 用户、GRANT
#   5. 导入 /workspace/public/install/data.sql 到 xinyue_search
#   6. 写 /workspace/.env，确保 DB 凭据正确
#   7. 清理 runtime 缓存
#   8. 在前台（或可选 --daemon 模式）启动 ThinkPHP dev server http://127.0.0.1:8000/
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORKSPACE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$WORKSPACE_DIR/.env"
DATA_SQL="$WORKSPACE_DIR/public/install/data.sql"
LOG_FILE="/tmp/mariadb.log"
SOCK_DIR="/run/mysqld"
SOCK_FILE="$SOCK_DIR/mysqld.sock"
PID_FILE="$SOCK_DIR/mysqld.pid"

DB_NAME="xinyue_search"
DB_USER="xinyue"
DB_PASS="xinyue_pass_2026"
DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_CHARSET="utf8mb4"
DB_PREFIX="qf_"

THINK_HOST="127.0.0.1"
THINK_PORT="8000"

MODE="foreground"
if [[ "${1:-}" == "--daemon" ]]; then
    MODE="background"
fi

log()  { printf "\033[1;36m==> %s\033[0m\n" "$*"; }
ok()   { printf "\033[1;32m✓ %s\033[0m\n" "$*"; }
warn() { printf "\033[1;33m⚠ %s\033[0m\n" "$*" >&2; }
err()  { printf "\033[1;31m✗ %s\033[0m\n" "$*" >&2; exit 1; }

# ---------- # 1. 安装 MariaDB（若缺失） ---------- #
need_install=0
for n in mariadbd mariadb; do
    command -v "$n" >/dev/null 2>&1 || { need_install=1; break; }
done
id mysql >/dev/null 2>&1 || need_install=1

if (( need_install )); then
    log "apt-get 安装 mariadb-server..."
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y >/dev/null || true
    apt-get install -y mariadb-server mariadb-client 2>&1 | tail -5
    ok "mariadb-server 安装完成"
fi

# ---------- # 2. 初始化数据目录 ---------- #
if [ ! -f /var/lib/mysql/ibdata1 ]; then
    log "初始化 /var/lib/mysql 系统表..."
    mkdir -p /var/lib/mysql
    chown mysql:mysql /var/lib/mysql
    chmod 700 /var/lib/mysql
    mariadb-install-db --user=mysql --datadir=/var/lib/mysql \
        --auth-root-authentication-method=socket 2>&1 | tail -5
    ok "数据目录初始化完成"
fi

# ---------- # 3. 启动 MariaDB daemon ---------- #
mkdir -p "$SOCK_DIR"
chown mysql:mysql "$SOCK_DIR" 2>/dev/null || true
chmod 755 "$SOCK_DIR" 2>/dev/null || true
rm -f "$PID_FILE" "$SOCK_FILE"

# kill 老进程（避免端口冲突）
pkill -9 mariadbd 2>/dev/null || true
sleep 1

MARIADBD_BIN="$(command -v mariadbd || command -v mysqld)"
log "启动 MariaDB (binary=$MARIADBD_BIN) ..."
setsid "$MARIADBD_BIN" --user=mysql --datadir=/var/lib/mysql \
  --socket="$SOCK_FILE" --pid-file="$PID_FILE" \
  --bind-address="$DB_HOST" --port="$DB_PORT" \
  </dev/null >>"$LOG_FILE" 2>&1 &
disown
DB_PID=$!
echo "  PID=$DB_PID"

# 等待 ready
log "等待 MariaDB 端口 $DB_PORT 就绪..."
READY=0
for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14; do
    if php -r '
$fp = @fsockopen("127.0.0.1", 3306, $en, $es, 1);
if ($fp) { fclose($fp); exit(0); }
exit(1);
' 2>/dev/null; then
        READY=1; break
    fi
    sleep 1.7
done
(( READY )) || err "MariaDB 未在端口 3306 就绪，请查看 $LOG_FILE"
ok "MariaDB 已就绪"

# ---------- # 4. 建库建用户 ---------- #
log "创建数据库 $DB_NAME 与用户 $DB_USER@$DB_HOST ..."
mariadb --socket="$SOCK_FILE" -u root <<EOSQL
  CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET $DB_CHARSET COLLATE ${DB_CHARSET}_unicode_ci;
  CREATE USER IF NOT EXISTS '$DB_USER'@'$DB_HOST' IDENTIFIED BY '$DB_PASS';
  ALTER USER '$DB_USER'@'$DB_HOST' IDENTIFIED BY '$DB_PASS';
  GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'$DB_HOST' WITH GRANT OPTION;
  FLUSH PRIVILEGES;
EOSQL
ok "数据库 & 用户已就绪"

# ---------- # 5. 导入 data.sql ---------- #
if [ -f "$DATA_SQL" ]; then
    log "导入 $DATA_SQL ..."
    php "$SCRIPT_DIR/import_sql.php"
else
    warn "找不到 $DATA_SQL，跳过导入"
fi

# ---------- # 6. 重写 .env ---------- #
log "写 $ENV_FILE ..."
cat > "$ENV_FILE" <<EOF
APP_DEBUG = true

[DATABASE]
TYPE = mysql
HOSTNAME = $DB_HOST
DATABASE = $DB_NAME
USERNAME = $DB_USER
PASSWORD = $DB_PASS
HOSTPORT = $DB_PORT
CHARSET = $DB_CHARSET
PREFIX = $DB_PREFIX

[LANG]
DEFAULT_LANG = zh-cn

[DEFAULT_TIMEZONE]
DEFAULT_TIMEZONE = Asia/Shanghai
EOF
ok ".env 已更新"

# ---------- # 7. 清 runtime ---------- #
log "清理 runtime 缓存..."
rm -rf "$WORKSPACE_DIR/runtime/cache" "$WORKSPACE_DIR/runtime/session"
mkdir -p "$WORKSPACE_DIR/runtime/log"
find "$WORKSPACE_DIR/runtime/log" -type f -delete 2>/dev/null || true
ok "缓存已清理"

# ---------- # 8. 启动 ThinkPHP server ---------- #
log "启动 ThinkPHP dev server  http://$THINK_HOST:$THINK_PORT/"
cd "$WORKSPACE_DIR"
if [[ "$MODE" == "background" ]]; then
    # 清掉已经存在的
    pkill -f "php think run --host $THINK_HOST --port $THINK_PORT" 2>/dev/null || true
    sleep 1
    setsid php think run --host "$THINK_HOST" --port "$THINK_PORT" </dev/null >>/tmp/thinkphp.log 2>&1 &
    disown
    TP_PID=$!
    sleep 3
    ok "ThinkPHP server 已后台启动, PID=$TP_PID, 日志 /tmp/thinkphp.log"
else
    # 前台（CTRL-C 停止）
    exec php think run --host "$THINK_HOST" --port "$THINK_PORT"
fi
