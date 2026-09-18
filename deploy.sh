#!/bin/bash
# =====================================================================
# deploy.sh  ととレジ — 本番サーバーへのデプロイ
# 使い方: bash deploy.sh
# =====================================================================

LOCAL="$HOME/Documents/Claude/Projects/富惣/FileMakerDataAPI/富惣_FileMakerDataAPI/tomiso/"
REMOTE="keiiti@keiiti.sakura.ne.jp:~/www/kei1/tomiso/"

# 注意：サーバーに絶対アップしたくない資料（提案書・社内Excel等）は
# 「除外指定」ではなく、必ず tomiso/ の外（例：一つ上の階層）に置くこと。
# 過去に「サーバにアップしない資料/」を --exclude していたが、macOS（NFD）と
# rsync除外パターン（NFC）の文字コード正規化の違いにより除外が効かず、
# 本番サーバーに誤ってアップロードされてしまった事故が発生している。
# tomiso/ の外に置けば、除外パターンのマッチに関係なく物理的に同期対象外になる。

echo "======================================"
echo " ととレジ デプロイ"
echo " → $REMOTE"
echo "======================================"
echo ""

# --dry-run で確認
echo "[確認] 変更されるファイル一覧（dry-run）:"
rsync -avz --dry-run --delete \
  --exclude='.git/' \
  --exclude='.gitignore' \
  --exclude='fm_config_secret.php' \
  --exclude='fm_config_secret.php.example' \
  --exclude='setup_git_mac.sh' \
  --exclude='deploy.sh' \
  --exclude='README.md' \
  --exclude='SPEC.md' \
  --exclude='docs/' \
  --exclude='sales_queue_data/' \
  --exclude='*_debug.php' \
  --exclude='star_debug_log.php' \
  --exclude='product_debug.php' \
  --exclude='debug_fm_layout.php' \
  --exclude='sales_entry_1.php' \
  "$LOCAL" "$REMOTE"

echo ""
read -p "上記の内容で本番にデプロイしますか？ (y/N): " answer
if [[ "$answer" != "y" && "$answer" != "Y" ]]; then
    echo "キャンセルしました。"
    exit 0
fi

echo ""
echo "[デプロイ中...]"
rsync -avz --delete \
  --exclude='.git/' \
  --exclude='.gitignore' \
  --exclude='fm_config_secret.php' \
  --exclude='fm_config_secret.php.example' \
  --exclude='setup_git_mac.sh' \
  --exclude='deploy.sh' \
  --exclude='README.md' \
  --exclude='SPEC.md' \
  --exclude='docs/' \
  --exclude='sales_queue_data/' \
  --exclude='*_debug.php' \
  --exclude='star_debug_log.php' \
  --exclude='product_debug.php' \
  --exclude='debug_fm_layout.php' \
  --exclude='sales_entry_1.php' \
  "$LOCAL" "$REMOTE"

echo ""
echo "✅ デプロイ完了"
echo "   https://kei1.me/tomiso/"
