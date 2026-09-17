#!/bin/bash

# 获取脚本所在的绝对目录
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# 拼接出目标仓库的绝对路径
TARGET_DIR="$SCRIPT_DIR/vendor/bearlord/yew-framework"

# 检查目录是否存在
if [ ! -d "$TARGET_DIR" ]; then
    echo "错误: 目录 $TARGET_DIR 不存在!"
    exit 1
fi

# 使用绝对路径执行 git 命令
# 1. 强制重置已跟踪文件的修改
git --git-dir="$TARGET_DIR/.git" --work-tree="$TARGET_DIR" reset --hard

# 2. 强制删除未跟踪的文件和目录（解决报错的关键步骤）
git --git-dir="$TARGET_DIR/.git" --work-tree="$TARGET_DIR" clean -fd

# 3. 拉取远程最新代码
git --git-dir="$TARGET_DIR/.git" --work-tree="$TARGET_DIR" pull
