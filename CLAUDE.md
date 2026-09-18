# ととレジ — プロジェクト設定

## システム概要
株式会社富惣 向け Web POS・売上管理システム。
FileMaker DataAPI (fmRESTor) を PHP バックエンドとして使用。

## 本番環境
- URL: https://kei1.me/tomiso/
- サーバー: keiiti@keiiti.sakura.ne.jp (さくらインターネット FreeBSD)
- サーバーパス: ~/www/kei1/tomiso/

## ローカル開発環境
- ローカルパス: ~/Documents/Claude/Projects/富惣/FileMakerDataAPI/富惣_FileMakerDataAPI/tomiso/
- GitHub: https://github.com/takasimak1/tomiso

## デプロイ手順
```bash
# 1. GitHub にコミット
git add -A
git commit -m "変更内容"
git push

# 2. 本番サーバーに反映
bash deploy.sh
```

## 重要ファイル
- `fm_config_secret.php` — FM API 認証情報（Git 管理外、サーバーのみ）
- `fm_setting.php` — FM 接続設定（レイアウト定義）
- `sales_entry.php` — POS レシート発行（中核ファイル）
- `src/fmRESTor.php` — FileMaker DataAPI ライブラリ
- `SPEC.md` — 詳細仕様・ロードマップ

## FileMaker レイアウト
| 変数 | レイアウト名 | 用途 |
|---|---|---|
| $layout_account | account_API | アカウント・インストアコード管理 |
| $layout_pos | pos_API | POS 明細 |
| $layout_hanbai | hanbai_API | 販売商品マスタ |
| $layout_daily_report | daily_report_API | 売上日報 |
| $layout_daily_report_sum | daily_report_sum_API | 日報集計 |
| $layout_haiki | haiki_API | 廃棄日報（商品単位・1日1回入力） |

## インストアコード仕様
JAN-13 形式: 店舗部門コード(7桁) + 金額(5桁) + チェックデジット(1桁) = 13桁
各店舗の部門コードは account_API の インストアコード_* フィールドで管理。

## 単品管理ダッシュボード関連
- `hanbai_API` に `上代単価`（数値・商品ごと固定値・本部設定）フィールドあり
- 上代（金額）＝上代単価 × 合計数量（定価数＋値引数＋廃棄数）で都度計算（保存はしない）
- 廃棄数は `haiki_API`（商品単位・1日1回・店舗スタッフ入力 `haiki_entry.php`）で管理

## 注意事項
- FM フィールド名に全角文字あり（例: `店舗Ｎｏ` は全角Ｎ）
- `fm_config_secret.php` は絶対に Git にコミットしない
- デバッグファイル (*_debug.php) は本番にデプロイしない
