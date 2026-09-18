# ととレジ システム仕様書

**株式会社富惣 / Web POS・売上管理システム**  
開発開始: 2024年  
リポジトリ: https://github.com/takasimak1/tomiso

---

## システム概要

FileMaker DataAPI をバックエンドとした、食品小売業向け Web POS・売上管理システム。
店舗スタッフが iPadなどのタブレット端末からレシート発行・日報入力を行い、
本社が売上集計・昨対比をリアルタイムで把握する。

---

## 現行構成

| レイヤー | 技術 |
|---|---|
| フロントエンド | HTML / CSS / Vanilla JS / Bootstrap 5 |
| バックエンド | PHP 8.x / fmRESTor ライブラリ |
| データベース | FileMaker Server（DataAPI 経由） |
| レシートプリンター | Star Micronics mPOP（StarWebPRNT） |
| バーコード | JsBarcode（EAN-13 インストアコード） |
| ホスティング | kei1.me（共用サーバー）|

### 主要ファイル構成

```
tomiso/
├── login.php               # ログイン（account_API 照合）
├── top.php                 # 店舗トップメニュー
├── sales_entry.php         # POS レシート入力・発行（★中核）
├── sales_confirm.php       # レシート訂正
├── daily_report_entry.php  # 日報入力（ととレジ実績列あり）
├── daily_report_mystore.php# 店舗向け月次サマリー
├── haiki_entry.php         # 廃棄数入力（商品単位・1日1回）
├── hq_top.php              # 本社メニュー
├── hq_seiseki.php          # 本社：店舗売上ランキング
├── hq_store.php            # 本社：店舗別日次推移
├── hq_jikanbetsu.php       # 本社：時間帯別集計
├── hq_shohin_maint.php     # 商品マスターメンテ
├── hq_tenpo_maint.php      # 店舗マスターメンテ（営業状態・インストアコード）
├── instore_codes.php       # インストアコード暫定設定（→FM移行予定）
├── fm_setting.php          # FM 接続設定（レイアウト定義）
└── fm_config_secret.php    # FM API 認証情報（.gitignore 除外）
```

### FileMaker レイアウト

| 変数名 | レイアウト名 | 用途 |
|---|---|---|
| $layout_tenpo | tenpo_API | 店舗マスタ |
| $layout_account | account_API | アカウント・インストアコード管理 |
| $layout_pos | pos_API | POS 明細（売上登録） |
| $layout_hanbai | hanbai_API | 販売商品マスタ（`上代単価`フィールドあり） |
| $layout_daily_report | daily_report_API | 売上日報 CRUD |
| $layout_daily_report_sum | daily_report_sum_API | 日報集計・昨対 |
| $layout_haiki | haiki_API | 廃棄日報（商品単位・1日1回入力） |

### 定休日（daily_report_entry.php）
店舗が「この日を定休日として確定する」ボタンで確定すると、部門別売上・客数
（12時/15時/17時/閉店後）をすべて0にして即確定する（`daily_report_API.定休日`=1、
数値フィールド）。未来の日付（最大30日先、日付ナビの上限と同じ）でも設定可能
（先の休業予定を事前登録できる）。自店舗のみが対象・閲覧可能。
0円確定の通常営業日と区別するため、`hq_store.php`（画面・CSV）と
`daily_report_mystore.php`で「🏠 定休日」表示に切り替える。確定解除（kaijo）時は
定休日フラグも0に戻す。日報画面にはアコーディオンで自店舗の定休日一覧（登録済み
全件、クリックでその日の日報画面へ遷移）を表示する。

`daily_report_mystore.php`（店舗向け月次サマリー）には「上代」「上代達成率」
（＝合計売上÷上代）列も追加。この画面は`daily_report_sum_API`という別レイアウト
を使っているため、`上代合計`・`定休日`フィールドをこちらにも配置する必要がある
（daily_report_APIと同じフィールド名で追加済み）。

`hq_nyuryoku.php`（本社：投入確認・全店日別売上マトリクス）は`daily_report_API`
（メインレイアウト）を使用しているため追加フィールド配置は不要。未確定と同様に
セル単位で「定休」表示に切り替え（画面・Excelエクスポート両対応）。

`hq_store.php`（本社：店舗別集計）にも「上代」「上代達成率」列を追加（画面・CSV
両対応）。追加の過程で、未来日／データなし行のセル数が既存列より1つ多い
既存バグ（表がずれる）を発見し、あわせて修正した。

### 単品管理ダッシュボード（hq_tanpin.php）

pos_API（定価/値引の実績）＋ haiki_API（廃棄数）＋ daily_report_API（店舗・日次の
上代目標）を突合し、クライアント提供のExcel仕様（①〜③シート）を1画面2タブに
統合して再現：
- タブ「推移」＝全店合計を日別集計（①相当）
- タブ「商品・店舗分析」→「商品別」＝商品ランキング、部門で絞り込み可（②-1/②-2相当）。
  商品名クリックで店舗別内訳にドリルダウン（②-3相当）
- タブ「商品・店舗分析」→「店舗別」＝選択店舗の商品別内訳（③相当）

列構成（共通）: 定価販売数/定価売上/定価率/値引き販売数/値引き売上/値引き率/廃棄数/廃棄率/合計/上代/上代達成率。
上代達成率＝(定価売上+値引売上)÷上代。CSV書き出し対応。

**上代の扱いに注意**: 「上代」は hanbai_API の商品マスタ単価ではなく、店舗が
売上日報（daily_report_entry.php）で毎日入力する**合計目標額1本**
（daily_report_API.上代合計。部門別ではなく単一の数値フィールド）。
商品・部門単位の内訳を持たないため、表示中の範囲が「全部門・全商品」と
一致する場合（推移タブ／商品別・部門絞り込みなし／店舗別）のみ意味を持つ。
「推移」タブは行ごとに、「商品別（全部門）」「店舗別」は合計行にのみ上代目標を
表示する。部門で絞り込んだ場合や単品→店舗ドリルダウン（②-3）では、店舗単位の
合計しか無く部門・商品を特定できないため上代は「―」表示とする。

---

## 機能一覧

### 店舗機能
- [x] POS レシート発行（部門別・値引き対応）
- [x] インストアコード（JAN-13）バーコード生成・印刷
- [x] Star Micronics プリンター対応（StarWebPRNT）
- [x] 日報入力（部門別売上、ととレジ実績との自動突合表示）
- [x] 月次売上サマリー表示（部門別・昨対）
- [x] 廃棄数入力（商品単位・1日1回）

### 本社機能
- [x] 店舗売上ランキング（当月累計・昨対）
- [x] 店舗別日次推移（部門別内訳）
- [x] 時間帯別集計
- [x] 商品マスターメンテ（取扱店舗・セール設定）
- [x] 店舗マスターメンテ（営業状態・閉店日・インストアコード）
- [x] 単品管理ダッシュボード（商品別・部門別・店舗別の定価/値引/廃棄/上代達成率）

---

## 今後の実装予定

### ① オフライン書き込みキュー（優先度: 高）

**背景**: 通信状態が悪い店舗でも、レシート印刷を確実に完了させる。

**方針**:
- レシート表示・印刷を FM 書き込みから切り離す
- 書き込み失敗時はサーバーローカルキューに保存
- アイドル時（ページロード・30秒タイマー）に自動再送信
- 未送信件数を店舗トップに表示

**レシート番号**:
- FM 書き込み成功: FM RecordId（現行と同じ）
- キュー時: `Q-YYYYMMDD-NNN` 形式

**実装ファイル**:
- `sales_queue.php`（新規）: キュー管理クラス
- `sales_queue_data/`（新規ディレクトリ、gitignore 済み）
- `sales_entry.php`（変更）: 書き込み前キュー保存・失敗フォールバック

---

### ② フレームワーク化・脱 FileMaker（優先度: 中長期）

**背景**: FileMaker Server の保守コスト・ライセンス費用・スケーラビリティの限界。
Web 標準技術へ段階移行し、長期運用コストを削減する。

**移行方針（段階的）**:

```
Phase 1（現行）
  FileMaker Server ← PHP（fmRESTor）← Browser

Phase 2（準備）
  FileMaker Server ← PHP（API抽象化レイヤー導入）← Browser
  ※ FM への依存を interface に閉じ込める

Phase 3（並行運用）
  FileMaker Server ↘
                    ← PHP（リポジトリパターン）← Browser
  MySQL/PostgreSQL ↗

Phase 4（移行完了）
  MySQL/PostgreSQL ← PHP Framework（Laravel 等）← Browser
```

**API 抽象化レイヤー設計**:
- `DataRepository` interface を定義
- `FileMakerRepository` が現行 FM DataAPI を実装
- `MysqlRepository` が移行後 DB を実装
- 切り替えは設定ファイル1行で完了する構造

**データモデル（予定）**:

| テーブル | 対応 FM レイアウト |
|---|---|
| stores | account_API / tenpo_API |
| products | hanbai_API |
| sales_receipts | pos_API（ヘッダー） |
| sales_items | pos_API（明細） |
| daily_reports | daily_report_API |

---

## 運用・セキュリティ

- `fm_config_secret.php` は `.gitignore` 除外（認証情報の Git 管理禁止）
- 本番サーバーへのデプロイは FTP / rsync（CI/CD 検討中）
- `sales_queue_data/` は Web 非公開ディレクトリに配置（`.htaccess` で直接アクセス禁止）

---

## 開発メモ

- FileMaker フィールド名に全角文字を多用（例: `店舗Ｎｏ`（全角Ｎ））。PHP 側でそのまま文字列キーとして使用
- 昨対比計算: `$max_cy_day` で当年最終日を基準に前年を同日数でカット（公平比較）
- インストアコード: `account_API` の `インストアコード_*` フィールド（テキスト7桁）で店舗別管理

