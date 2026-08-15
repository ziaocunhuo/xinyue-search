#!/bin/bash
# 心悦搜索 Docker 一键部署脚本
# 绿联 NAS / Linux 通用
set -e

echo "============================================"
echo "  心悦搜索 Docker 一键部署"
echo "============================================"
echo

if ! command -v docker &> /dev/null; then
    echo "[错误] 未检测到 docker，请先安装 Docker"
    exit 1
fi

if ! docker compose version &> /dev/null && ! command -v docker-compose &> /dev/null; then
    echo "[错误] 未检测到 docker compose / docker-compose"
    exit 1
fi

USE_DC="docker compose"
if ! docker compose version &> /dev/null; then
    USE_DC="docker-compose"
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

echo "[1/5] 创建数据目录..."
mkdir -p data/{mariadb,nginx-logs,php-sessions,php-logs}

echo "[2/5] 清理旧容器（如果存在）..."
$USE_DC down -v 2>/dev/null || true

echo "[3/5] 构建 PHP 镜像..."
$USE_DC build --no-cache php

echo "[4/5] 启动所有服务..."
$USE_DC up -d

echo "[5/5] 等待服务就绪..."
sleep 5

echo
echo "============================================"
echo "  部署完成！"
echo "============================================"
echo
echo "  访问地址: http://<NAS_IP>:8091"
echo "  查看日志: $USE_DC logs -f"
echo "  停止服务: $USE_DC down"
echo "  重启服务: $USE_DC restart"
echo
echo "  数据库信息（首次启动会自动建库）："
echo "    库名: xinyue_search"
echo "    用户名: xinyue"
echo "    密码: xinyue_pass_2026"
echo "    Root密码: xinyue_root_2026"
echo
