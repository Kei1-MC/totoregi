# ととレジ — 株式会社富惣 Web POS システム

FileMaker DataAPI を使った、食品小売業向け Web POS・売上管理システム。

---

## ローカル開発環境（MAMP + GitHub）

### 前提
- macOS + MAMP（無料版で可）
- PHP 8.1 以上
- Git

---

### 手順 1 — MAMP の PHP バージョン設定

1. MAMP を起動
2. 「Preferences」→「PHP」→ バージョンを **8.1** 以上に設定
3. 「Start Servers」でサーバーを起動

---

### 手順 2 — プロジェクトを MAMP htdocs に配置

**① GitHub からクローン（推奨）**

```bash
cd /Applications/MAMP/htdocs
git clone https://github.com/Kei1-MC/totoregi.git tomiso
```

アクセス URL: `http://localhost:8888/tomiso/`

---

**② MAMP の Document Root を変更する方法（別の場所にすでにある場合）**

1. MAMP「Preferences」→「Web Server」
2. Document Root を プロジェクトの **親フォルダ** に変更
   - 例: `~/Documents/開発/富惣/` に `tomiso/` フォルダがある場合
3. アクセス URL: `http://localhost:8888/tomiso/`

---

### 手順 3 — 機密ファイルを作成

```bash
cd /Applications/MAMP/htdocs/tomiso   # またはプロジェクトの場所

cp fm_config_secret.php.example fm_config_secret.php
```

`fm_config_secret.php` をテキストエディタで開き、FileMaker API の認証情報を入力:

```php
<?php
$api_master_user = 'ここにユーザー名';
$api_master_pass = 'ここにパスワード';
```

> ⚠️ このファイルは `.gitignore` で管理されており、Git には入りません。

---

### 手順 4 — 動作確認

ブラウザで `http://localhost:8888/tomiso/` を開き、ログイン画面が表示されればOK。

> FileMaker への接続は本番サーバー（sys.kei1.me）を使います。  
> ローカルでも実データを参照・更新します。

---

## GitHub 初回セットアップ（まだリポジトリがない場合）

```bash
cd プロジェクトのtomisoフォルダ
bash setup_git_mac.sh
```

---

## 日常的な開発フロー

```bash
# 変更したら
git add -A
git commit -m "変更内容のメモ"
git push

# 本番に反映
bash deploy.sh
```

---

## 本番サーバーへのデプロイ

```bash
bash deploy.sh
```

rsync で差分のみ転送。`fm_config_secret.php` は除外されます。

---

## 主なファイル

| ファイル | 説明 |
|---|---|
| `login.php` | ログイン |
| `top.php` | 店舗ダッシュボード（お知らせ表示） |
| `sales_entry.php` | POS 売上登録（中核） |
| `daily_report_entry.php` | 売上日報入力・確定 |
| `hq_top.php` | 本社トップ |
| `hq_nyuryoku.php` | 本社：投入確認（全店日別マトリクス） |
| `hq_seiseki.php` | 本社：昨対ランキング |
| `hq_tenpo_maint.php` | 本社：店舗マスター管理 |
| `fm_setting.php` | FileMaker レイアウト定義 |
| `fm_config_secret.php` | API 認証情報（**Git 管理外**） |
| `SPEC.md` | 詳細仕様 |

---

## 注意事項

- `fm_config_secret.php` は `.gitignore` で除外済み（絶対にコミットしない）
- `sales_queue_data/` は Git 管理外（ランタイム生成）
- デバッグ用 `*_debug.php` も `.gitignore` で除外済み
