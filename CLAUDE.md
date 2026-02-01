# CLAUDE.md - 永続メモリ & 自己改善ログ

> ## ⚠️ 最重要指示: 記憶の更新は義務である ⚠️
>
> **このファイルはお前（Claude Code）の唯一の永続メモリである。セッションが終われば全てを忘れる。このファイルだけが次の自分に知識を引き継ぐ手段だ。**
>
> **以下のルールは絶対に守れ:**
> 1. **作業開始時**: このファイルを最初に全て読め。ここに書いてあることが「お前の記憶」だ
> 2. **作業中**: コードを変更したら、バグを見つけたら、新しい知見を得たら、設計を変えたら — **その場で即座にこのファイルを更新しろ**。「後でまとめて更新」は禁止。忘れるからだ
> 3. **作業終了前**: セッション終了前に必ずこのファイルを見直し、今回の作業内容が全て記録されているか確認しろ
> 4. **更新を怠った場合**: 次のセッションの自分が同じバグを踏む。同じ調査をやり直す。ユーザーの時間を無駄にする。それはお前の責任だ
>
> ## 運用ルール
> 1. **毎回の作業開始時**にこのファイルを読み込み、内容に従って行動する
> 2. **作業中に新しい知見・決定・変更が生じたら**、即座にこのファイルを更新する（追記・修正・削除）
> 3. **更新対象**: アーキテクチャ変更、新しい依存関係、デプロイ設定、踏んだ罠・解決策、環境差異、運用ルール、バージョン変更、バグ修正など
> 4. このファイルの情報が古くなった場合は削除・修正し、常に最新状態を維持する
> 5. **あとで思い出せるように書く**: 技術的な知見を記録する際は、調査元の公式ドキュメントURL・GitHubリポジトリ・SDKソースファイルパスなどの**情報ソース**も一緒に記録する
> 6. **セクションは自由に増減してよい**: 新しいテーマが出てきたらセクションを追加し、不要になったら統合・削除する
> 7. **自己改善**: ユーザーに指摘された間違い・非効率・判断ミスは「自己改善ログ」セクションに記録する
> 8. **常時更新の義務**: 新情報の発見、コードリーディング中の新発見、設計変更、技術的知見の獲得、バグの発見と修正など — あらゆる新たな情報や更新が発生した場合は**必ずその場でこのファイルを更新する**
> 9. **バージョン更新時**: プラグインのバージョンを変更したら、このファイル内の全てのバージョン記述も合わせて更新する
> 10. **🚨🚨🚨 コード変更時のバージョン更新は最優先義務 🚨🚨🚨**:
>     - **絶対ルール**: プラグインの PHP/JS/CSS コードに **1行でも変更** を加えたら、**必ずバージョン番号をインクリメント** してからコミットしろ。例外は一切ない
>     - **バージョン更新なしのコード変更コミットは厳禁**。これを破ると WordPress のアップデート機構が壊れ、ユーザーに更新が届かなくなる
>     - **タイミング**: コード変更の **最初のステップ** としてバージョンを上げろ。「後で上げよう」は禁止。忘れるからだ
>     - **バージョニング規則**: バグ修正・軽微変更 → パッチ (x.y.Z)、機能追加 → マイナー (x.Y.0)、破壊的変更 → メジャー (X.0.0)
>     - **更新必須箇所 (4箇所)**:
>       1. `readonly-ability-plugin.php` — プラグインヘッダー `Version:` の値
>       2. `readonly-ability-plugin.php` — `WP_MCP_PLUGIN_VERSION` 定数の値
>       3. `readme.txt` — `Stable tag:` の値 + `== Changelog ==` セクションに変更内容を追記
>       4. `CLAUDE.md` — Constants テーブルの `WP_MCP_PLUGIN_VERSION` + 更新履歴テーブルに追記
>     - **確認手順**: バージョン更新後、`grep -rn '1.x.x' readonly-ability-plugin.php readme.txt` で全箇所が一致していることを確認しろ
>     - **過去の失敗**: バージョン更新を忘れてユーザーに指摘された実績あり。二度と繰り返すな
> 11. **⚠️ PHPコード変更後の構文チェックは必須**: PHPファイルを変更したら、**必ず `php -l` で構文チェックを実行**してから完了報告すること。コマンド: `find <project> -name '*.php' -not -path '*/vendor/*' -exec php -l {} \; 2>&1 | grep -v "No syntax errors"` — 出力がゼロであることを確認。構文チェックなしでの完了報告は禁止

## Package Management (STRICT)
- **PHP依存管理**: `composer require <package>` のみ使用。手動でvendorを編集しない
- **ロック同期**: `composer install` でlockファイルから再現
- **本番ビルド**: `composer install --no-dev --optimize-autoloader`

## Project Overview
- **プラグイン名**: WordPress MCP Ability Suite
- **目的**: WordPress のコンテンツ操作を MCP (Model Context Protocol) 経由で AI エージェントや外部アプリに公開する
- **Author**: als141
- **ライセンス**: GPL-2.0-or-later
- **WordPress.org 公開準備中** (feat/oauth ブランチで開発 → master にマージ済み)

## Tech Stack
- **言語**: PHP 8.1+
- **フレームワーク**: WordPress 6.0+ (6.9+ 推奨)
- **プロトコル**: MCP 2025-06-18 仕様 (JSON-RPC 2.0 over Streamable HTTP)
- **依存パッケージ**:
  - `wordpress/abilities-api` ^0.4 || ^0.5 — WordPress Abilities API
  - `wordpress/mcp-adapter` ^0.4 — MCP サーバー実装
  - `automattic/jetpack-autoloader` ^5.0 — 名前空間衝突防止
- **情報ソース**:
  - WordPress Abilities API: https://github.com/WordPress/abilities-api
  - WordPress MCP Adapter: https://github.com/WordPress/mcp-adapter
  - MCP 仕様: https://spec.modelcontextprotocol.io/
  - WordPress Plugin Guidelines: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/

## Project Structure
```
wordpress-ability-plugin/
├── readonly-ability-plugin.php    # メインプラグインファイル (188行)
├── includes/
│   ├── helpers.php                # ヘルパー関数 10個 (163行)
│   ├── mcp-server.php             # MCP サーバー初期化 (119行)
│   ├── abilities/
│   │   ├── categories.php         # アビリティカテゴリ 9個 (56行)
│   │   ├── tools.php              # MCP ツール 25個 (1,655行)
│   │   ├── resources.php          # MCP リソース 4個 (168行)
│   │   └── prompts.php            # MCP プロンプト 4個 (347行)
│   └── saas-auth/
│       ├── class-saas-auth-provider.php  # 認証コア (1,023行)
│       ├── class-api-key-manager.php     # API キー管理 (453行)
│       ├── class-oauth-metadata.php      # OAuth/.well-known メタデータ (416行)
│       └── class-admin-settings.php      # 管理画面UI + 連携フロー (672行)
├── vendor/                        # Composer 依存（bundled）
│   ├── wordpress/abilities-api/
│   ├── wordpress/mcp-adapter/
│   └── jetpack-autoloader/
├── docs/
│   ├── APP_INTEGRATION.md         # アプリ連携実装ガイド (接続URL方式)
│   └── EXTENSION-REQUIREMENTS.md  # 拡張要件書 (55KB)
├── .wordpress-org/                # WordPress.org アセット画像
│   ├── icon-256x256.png
│   ├── icon-128x128.png
│   ├── banner-772x250.png
│   └── banner-1544x500.png
├── .github/workflows/deploy.yml   # GitHub Actions → WordPress.org SVN デプロイ
├── .distignore                    # SVN 除外ファイル一覧
├── composer.json
├── build.sh                       # ZIP ビルドスクリプト
├── readme.txt                     # WordPress.org 用 readme
├── LICENSE                        # GPLv2
└── README.md
```
**合計**: 約 5,260行のカスタム PHP コード（vendor 除外）

## Constants
| 定数 | 値 | 定義場所 |
|------|---|---------|
| `WP_MCP_PLUGIN_VERSION` | `'1.1.1'` | readonly-ability-plugin.php |
| `WP_MCP_ABILITY_PREFIX` | `'wp-mcp'` | readonly-ability-plugin.php |

## MCP Tools (25個)

### 分析ツール (Analysis)
| ツール名 | パラメータ | 権限 | 概要 |
|----------|-----------|------|------|
| `wp-mcp/get-posts-by-category` | `category_id` (必須), `limit`, `order`, `orderby` | read | カテゴリ別記事一覧 |
| `wp-mcp/get-post-block-structure` | `post_id` (必須) | read | Gutenberg ブロック構造解析 |
| `wp-mcp/analyze-category-format-patterns` | `category_id` (必須), `sample_count` | read | カテゴリ内記事の共通パターン抽出 |
| `wp-mcp/get-post-raw-content` | `post_id` (必須) | read | 生ブロック HTML + レンダリング済み HTML |
| `wp-mcp/extract-used-blocks` | `post_type`, `limit` | read | ブロック使用頻度集計 |

### スタイル・規約ツール (Style)
| ツール名 | パラメータ | 権限 | 概要 |
|----------|-----------|------|------|
| `wp-mcp/get-theme-styles` | なし | read | テーマグローバルスタイル |
| `wp-mcp/get-block-patterns` | `category` | read | 登録ブロックパターン一覧 |
| `wp-mcp/get-reusable-blocks` | `per_page` | read | 再利用ブロック一覧 |
| `wp-mcp/get-article-regulations` | `category_id` | read | 記事執筆規約 |

### コンテンツ管理ツール (Content)
| ツール名 | パラメータ | 権限 | 概要 |
|----------|-----------|------|------|
| `wp-mcp/create-draft-post` | `title` (必須), `content` (必須), `category_ids`, `tag_ids`, `excerpt`, `meta` | edit_posts | 下書き作成 |
| `wp-mcp/update-post-content` | `post_id` (必須), `content` (必須), `title` | edit_posts | 記事更新 |
| `wp-mcp/update-post-meta` | `post_id` (必須), `meta_key` (必須), `meta_value` (必須) | edit_post_meta | メタデータ更新 |
| `wp-mcp/publish-post` | `post_id` (必須), `scheduled_time` | publish_posts | 公開・予約投稿 |
| `wp-mcp/delete-post` | `post_id` (必須), `force` | delete_posts | 削除・ゴミ箱 |

### バリデーションツール (Validation)
| ツール名 | パラメータ | 権限 | 概要 |
|----------|-----------|------|------|
| `wp-mcp/validate-block-content` | `content` (必須) | read | ブロック構文検証 |
| `wp-mcp/check-regulation-compliance` | `content` (必須), `category_id` (必須) | read | 規約準拠チェック |
| `wp-mcp/check-seo-requirements` | `content` (必須), `target_keywords`, `title` | read | SEO 要件チェック |

### メディアツール (Media)
| ツール名 | パラメータ | 権限 | 概要 |
|----------|-----------|------|------|
| `wp-mcp/get-media-library` | `search`, `mime_type`, `per_page` | upload_files | メディアライブラリ検索 |
| `wp-mcp/upload-media` | `source` (必須), `filename` (必須), `title`, `alt`, `caption` | upload_files | URL/Base64 からアップロード |
| `wp-mcp/set-featured-image` | `post_id` (必須), `media_id` (必須) | upload_files | アイキャッチ画像設定 |

### タクソノミーツール (Taxonomy)
| ツール名 | パラメータ | 権限 | 概要 |
|----------|-----------|------|------|
| `wp-mcp/get-categories` | `parent`, `hide_empty` | read | カテゴリ一覧 |
| `wp-mcp/get-tags` | `search`, `per_page` | read | タグ一覧 |
| `wp-mcp/create-term` | `taxonomy` (必須), `name` (必須), `slug`, `parent` | manage_categories / manage_post_tags | カテゴリ/タグ作成 |

### サイト情報ツール (Site)
| ツール名 | パラメータ | 権限 | 概要 |
|----------|-----------|------|------|
| `wp-mcp/get-site-info` | なし | read | サイトメタ情報 |
| `wp-mcp/get-post-types` | `public_only` | read | 投稿タイプ一覧 |

## MCP Resources (4個)
| リソース名 | URI | 概要 |
|-----------|-----|------|
| `wp-mcp/block-schemas` | `wordpress://mcp/block_schemas` | ブロックタイプレジストリ |
| `wp-mcp/style-guide` | `wordpress://mcp/style_guide` | テーマスタイル + カスタムガイドライン |
| `wp-mcp/category-templates` | `wordpress://mcp/category_templates` | カテゴリ別ブロックテンプレート |
| `wp-mcp/writing-regulations` | `wordpress://mcp/writing_regulations` | 記事執筆規約 |

## MCP Prompts (4個)
| プロンプト名 | パラメータ | 概要 |
|-------------|-----------|------|
| `wp-mcp/article-generation` | `category_id`, `keywords`, `word_count` | 記事生成プロンプト |
| `wp-mcp/format-conversion` | `plain_text` (必須), `target_format` | プレーンテキスト→ブロック変換 |
| `wp-mcp/seo-optimization` | `post_id` (必須), `target_keywords` | SEO 最適化提案 |
| `wp-mcp/regulation-learning` | `category_id` (必須), `sample_count` | サンプル記事から規約抽出 |

## REST API Endpoints
| メソッド | エンドポイント | 認証 | 目的 |
|---------|--------------|------|------|
| POST | `/wp-json/mcp/mcp-adapter-default-server` | Bearer/ApiKey/Basic | MCP JSON-RPC サーバー |
| POST | `/wp-json/wp-mcp/v1/register` | なし (registration_code) | アプリ登録コード交換 |
| GET | `/wp-json/wp-mcp/v1/connection-callback` | なし | アプリコールバック (後方互換) |
| GET | `/wp-json/wp-mcp/v1/connection-status/{connection_id}` | manage_options | 接続状態ポーリング (管理画面用) |
| GET | `/wp-json/wp-mcp/v1/api-keys` | ログインユーザー | API キー一覧 |
| POST | `/wp-json/wp-mcp/v1/api-keys` | ログインユーザー | API キー作成 |
| DELETE | `/wp-json/wp-mcp/v1/api-keys/{key_id}` | ログインユーザー | API キー削除 |
| GET | `/wp-json/wp-mcp/v1/oauth/protected-resource` | なし | RFC 9728 メタデータ |
| GET | `/wp-json/wp-mcp/v1/oauth/authorization-server` | なし | RFC 8414 メタデータ |
| GET | `/wp-json/wp-mcp/v1/mcp/metadata` | なし | MCP メタデータ |
| GET | `/.well-known/oauth-protected-resource` | なし | .well-known (rewrite) |
| GET | `/.well-known/oauth-authorization-server` | なし | .well-known (rewrite) |
| GET | `/.well-known/mcp.json` | なし | .well-known (rewrite) |

## WordPress Options
| オプション名 | 用途 | 型 |
|-------------|------|---|
| `wp_mcp_saas_settings` | 認証設定 | array |
| `wp_mcp_saas_api_keys` | グローバル API キー | array |
| `wp_mcp_access_tokens` | アクセストークン（SHA256ハッシュ） | array |
| `wp_mcp_connections` | **複数アプリ連携情報** (v1.1.0~) | array (connection_id → data) |
| `wp_mcp_registration_code` | 一時登録コード（10分有効、connection_id 含む） | array |
| `wp_mcp_auth_logs` | 認証監査ログ | array |
| `mcp_article_regulations` | カテゴリ別記事規約 | array |
| `mcp_block_templates` | カテゴリ別ブロックテンプレート | array |
| `mcp_style_guidelines` | カスタムスタイルガイドライン | array |
| `wp_mcp_saas_connection` | ~~旧: 単一接続情報~~ (レガシー、マイグレーション後は未使用) | array |
| `wp_mcp_saas_url` | ~~旧: 接続先 URL~~ (レガシー、マイグレーション後は未使用) | string |

## User Meta Keys
| メタキー | 用途 |
|---------|------|
| `_wp_mcp_api_key` | ユーザーの API キーハッシュ |
| `_wp_mcp_api_key_data` | API キーメタデータ |

## Authentication Flow (アプリ連携)

### v1.1.0 接続URL方式 (推奨)
```
管理者 → 連携ネーム入力 → [接続URLを生成する] ボタンクリック
  ↓
WordPress: registration_code 生成 (10分有効) + 接続URL表示
  ↓
管理者: 接続URLをアプリにコピー＆ペースト
  ↓
アプリ → POST /wp-mcp/v1/register (registration_code 送信)
  ↓
WordPress: コード検証 → 永続 access_token + api_key + api_secret を返却
  ↓
管理画面: AJAX ポーリングで接続完了を自動検知 → 「連携完了」表示
  ↓
以降: アプリは Authorization: Bearer {access_token} で MCP 通信
```

### 接続URL形式
```
https://your-site.com/wp-json/wp-mcp/v1/register?code={registration_code}
```
アプリ側はこのURLからサイトURL・エンドポイント・コードを全て取得できる。

### 認証方式（MCP リクエスト時）
1. **Bearer Token (推奨)**: `Authorization: Bearer {access_token}` — SHA256 ハッシュで照合、永続
2. **JWT**: `Authorization: Bearer {jwt}` — HMAC-SHA256 署名検証
3. **Basic Auth**: `Authorization: Basic base64(api_key:api_secret)` — 代替手段
4. **ApiKey + Signature**: `Authorization: ApiKey {api_key}:{signature}` — HMAC 署名

## SaaS側（marketing-automation）との連携仕様

SaaS 側のクライアント実装 (`wordpress_mcp_service.py`):
- **プロトコル**: JSON-RPC 2.0 over HTTP (Streamable HTTP)
- **MCP バージョン**: `2024-11-05`
- **クライアント名**: `marketing-automation-blog-ai` v1.0.0
- **タイムアウト**: 通常60秒、メディアアップロード300秒
- **セッション管理**: `Mcp-Session-Id` ヘッダーで維持
- **自動再接続**: セッションエラー（code -32600 or "session"）時に1回リトライ
- **クレデンシャル保存**: AES-256-GCM 暗号化（Supabase `wordpress_sites` テーブル）
- **使用フィールド**: `access_token` のみ（`api_key`, `api_secret` は保存のみで未使用）
- **レスポンス解析優先順**: `structuredContent` > `content[0].text` > raw result JSON
- **テスト接続**: 4ステップ（クレデンシャル → エンドポイント到達性 → MCP初期化 → ツール呼び出し）

### 互換性の注意点
| 項目 | 状態 | 詳細 |
|------|------|------|
| ツール名形式 | **要確認** | SaaS: `wp-mcp-get-site-info` (ハイフン) vs プラグイン: `wp-mcp/get-site-info` (スラッシュ). MCP Adapter が変換している可能性あり |
| `structuredContent` | **未対応** | SaaS 側は `structuredContent` を優先参照するがプラグイン側は `content[0].text` のみ |
| `notifications/initialized` | **SaaS未送信** | MCP 仕様で必要な初期化通知を SaaS が送信していない |
| セッションID | OK | 両方で `Mcp-Session-Id` を扱っている |
| Bearer 認証 | OK | 互換性あり |

## WordPress Hooks

### Actions
| フック | ファイル | 目的 |
|--------|---------|------|
| `mcp_adapter_init` | mcp-server.php | MCP サーバー初期化 |
| `wp_abilities_api_init` | tools/resources/prompts.php | アビリティ登録 |
| `wp_abilities_api_categories_init` | categories.php | カテゴリ登録 |
| `init` | class-saas-auth-provider.php, class-oauth-metadata.php | 初期化 |
| `rest_api_init` | 全 saas-auth クラス | REST ルート登録 |
| `admin_menu` | class-admin-settings.php | 設定ページ追加 |
| `admin_init` | class-admin-settings.php | フォームアクション処理 |
| `template_redirect` | class-oauth-metadata.php | .well-known リクエスト処理 |

### Filters
| フィルター | ファイル | 目的 |
|-----------|---------|------|
| `wp_mcp_server_id` | mcp-server.php | サーバーID カスタマイズ |
| `wp_mcp_server_namespace` | mcp-server.php | REST 名前空間 |
| `wp_mcp_server_route` | mcp-server.php | ルートパス |
| `wp_mcp_tools` | mcp-server.php | ツールリストフィルタ |
| `wp_mcp_resources` | mcp-server.php | リソースリストフィルタ |
| `wp_mcp_prompts` | mcp-server.php | プロンプトリストフィルタ |

## Development Commands
- **ビルド (ZIP)**: `bash build.sh` → `wordpress-mcp-ability-plugin-1.0.0.zip` を生成 (約524K)
- **依存インストール**: `composer install`
- **本番ビルド**: `composer install --no-dev --optimize-autoloader`
- **デプロイ**: GitHub にタグ push → GitHub Actions → WordPress.org SVN 自動デプロイ

### コード品質チェック
- **構文チェック**: `composer run lint` — 全 PHP ファイルの `php -l` 構文検証
- **静的解析**: `composer run analyse` — PHPStan level 5 で型・ロジック検証
- **全チェック**: `composer run check` — lint + analyse を順次実行
- **pre-push hook**: `git push` 時に自動で `lint` + `analyse` が実行される。エラーがあれば push を拒否
- **PHPStan 設定**: `phpstan.neon` — level 5, WordPress stubs 対応, PHPDoc 型推論緩和あり
- **dev 依存**: `phpstan/phpstan`, `phpstan/extension-installer`, `szepeviktor/phpstan-wordpress`

### GitHub Actions CI/CD
- **ci.yml**: push/PR 時に `lint` + `PHPStan` を自動実行。チェック失敗でマージブロック可能
- **release.yml**: `v*` タグ push 時 → チェック通過後に ZIP ビルド → GitHub Releases にアップロード
- **deploy.yml**: `*` タグ push 時 → チェック通過後に WordPress.org SVN デプロイ + ZIP Release
- **フロー**: check ジョブ (`needs: check`) が通らない限り build/deploy は実行されない

## Deployment
- **ターゲット**: WordPress.org プラグインディレクトリ
- **デプロイ方式**: GitHub Actions (`10up/action-wordpress-plugin-deploy`)
- **必要シークレット**: `SVN_USERNAME`, `SVN_PASSWORD` (GitHub Secrets)
- **トリガー**: `v*` タグ push (例: `v1.0.0`)
- **アセット画像**: `.wordpress-org/` ディレクトリに格納
- **SVN 除外**: `.distignore` に定義済み

## WordPress.org 公開準備ステータス
- [x] LICENSE ファイル (GPLv2)
- [x] プラグインヘッダー (全フィールド)
- [x] 全 PHP ファイルに ABSPATH チェック
- [x] REST API パラメータのサニタイズ
- [x] readme.txt (FAQ, Changelog, Privacy 含む)
- [x] .distignore
- [x] GitHub Actions デプロイワークフロー
- [x] アセット画像 (icon + banner)
- [x] master ブランチにマージ済み
- [ ] 以下のバグ修正（後述）
- [ ] Plugin Check (PCP) ツールでの全項目チェック
- [ ] GitHub リポジトリの公開設定
- [ ] WordPress.org 審査リクエスト送信

---

## Known Bugs (コードレビューで発見 → 修正済み/未修正)

### 修正済み (2026-02-01)

| ID | 概要 | ファイル | 修正内容 |
|----|------|---------|---------|
| BUG-001 | `get-block-patterns` スキーマ不一致 | `tools.php` | `return array('items' => array())` に修正 |
| BUG-002 | `create-term` slug 欠落 | `tools.php` | `get_term()` で正しい slug を取得 |
| BUG-004 | `/auth/test` 本番公開 | `class-saas-auth-provider.php` | `WP_DEBUG` 時のみ登録に変更 |
| BUG-005 | `/token/introspect` 認証なし + permanent token 判定ミス | `class-saas-auth-provider.php` | 認証必須化 + permanent token 判定修正 |
| BUG-006 | API secret 平文保存 | `class-api-key-manager.php` | `wp_hash_password()` でハッシュ化、レガシー互換あり |
| BUG-024 | `authenticate_basic()` が `secret_hash` 非対応 | `class-saas-auth-provider.php` | `validate_credentials()` と同じレガシー互換ロジックを適用 |
| BUG-008 | `get-categories`/`get-tags` WP_Error チェックなし | `tools.php` | `is_wp_error()` チェック追加 |
| BUG-009 | `create-draft-post` meta 保護キー書き込み可能 | `tools.php` | `is_protected_meta()` チェック追加 |
| BUG-010 | `update-post-meta` unchanged で false | `tools.php` | `false !== $updated` で判定修正 |
| BUG-011 | `get-tags` hide_empty デフォルト true | `tools.php` | `'hide_empty' => false` 明示追加 |
| BUG-012 | OAuth メタデータ未実装機能宣言 | `class-oauth-metadata.php` | 未実装項目削除 (authorization_code, PKCE, jwks_uri 等) |
| BUG-013 | disconnect 時 API Key 残存 | `class-admin-settings.php` | 接続ユーザーの API Key も削除 |
| BUG-016 | SEO H1 偽陽性 | `tools.php` | H1 検出時に重複警告に反転 |
| BUG-017 | CJK キーワード密度不正確 | `tools.php` | CJK 検出時は文字ベース密度計算に分岐 |
| BUG-020 | `get-site-info` admin_email 露出 | `tools.php` | `manage_options` 権限時のみ返却 |
| BUG-022 | Prompts 無効 ID でエラーなし | `prompts.php` | 無効 ID 時に WP_Error 返却 |
| BUG-023 | `wp_admin_notice()` WP 6.4+ 必要 | `readonly-ability-plugin.php` | `function_exists()` フォールバック追加 |

### 未修正 (低リスク / 今後対応)

| ID | 概要 | 理由 |
|----|------|------|
| BUG-003 | `upload-media` テンポラリファイルリーク | 再調査の結果バグではない。`media_handle_sideload` 成功時はファイル移動済み、`$cleanup` は正しい位置にある |
| BUG-007 | Token ストレージ競合状態 | WordPress の `update_option` は autoload 行ロック有り。実運用で問題になる可能性は低い |
| BUG-014 | `mark_connected` user_id=0 | REST API の `permission_callback` が `manage_options` 必須のためログイン必須。バグではない |
| BUG-015 | API Key LIKE 検索 | `validate_credentials` で hash_equals による完全一致検証が既にあり実害なし |
| BUG-018 | Resources の uri 入力無視 | MCP プロトコル準拠のためスキーマは残す。実害なし |
| BUG-019 | 空白ノードカウント | WordPress コアの `parse_blocks()` 慣例に従った動作 |
| BUG-021 | upload-media SSRF | `download_url()` 内部で `wp_safe_remote_get()` 使用。WordPress コアが防御済み |

---

## Documentation (docs/ ディレクトリ)

| ファイル | 内容 | 更新タイミング |
|---------|------|-------------|
| `docs/APP_INTEGRATION.md` | **アプリ連携実装ガイド** — 接続URL方式の連携フロー、登録API仕様、ローカルツールラッパー方式の推奨理由（海外IPアクセス制限回避）、全25ツール・4リソース・4プロンプトの完全リファレンス、MCP クライアント実装例（Python/Node.js）、DB設計、暗号化、セキュリティ | ツール追加・削除・パラメータ変更時、認証方式変更時、連携フロー変更時に必ず更新 |
| `docs/EXTENSION-REQUIREMENTS.md` | 拡張要件書 | 要件変更時 |

### ドキュメント更新ルール
- **ツール・リソース・プロンプトの追加/削除/変更** を行ったら、`docs/APP_INTEGRATION.md` のリファレンスセクションも必ず同期更新
- **連携フロー（認証、登録API、接続URL形式等）** を変更したら、ドキュメントのフローセクションも更新
- **旧 `docs/SAAS_INTEGRATION.md` は削除済み** (v1.1.0 で接続URL方式に移行)。新しいガイドは `APP_INTEGRATION.md`
- marketing-automation リポジトリの実装パターンを参考例として記載済み

## Extension Points (カスタマイズ)
- **カスタムツール追加**: `wp_abilities_api_init` フックで `wp_register_ability()` を呼ぶ
- **ツールリスト変更**: `wp_mcp_tools` フィルタ
- **認証カスタマイズ**: MCP サーバーの `permission_callback` をオーバーライド
- **規約設定**: `mcp_article_regulations` オプションを更新

## Troubleshooting Log
- **404 on /wp-mcp/v1/register**: `class-admin-settings.php` の REST ルート登録が `is_admin()` 内にラップされていた → REST コンテキストではフロントエンドなので `is_admin()` は false → `Admin_Settings::instance()` の初期化を `is_admin()` の外に移動して解決
- **mcp-server.php 重複 ABSPATH チェック**: `exit` + `return` が両方あった → `exit` のみに統一
- **プラグイン更新時の SaaS 連携**: 更新しても連携は切れない。全データは `wp_options`/`user_meta` に保存されており、プラグインディレクトリのファイル入れ替えでは消えない。SaaS 側は Bearer Token (permanent) のみ使用。activation hook は既存設定がある場合何もしない。uninstall hook は未実装なのでアンインストールしてもデータは残る
- **`declare(strict_types=1)` + `namespace` の順序制約**: PHP では `declare` はファイルの最初のステートメント、`namespace` は `declare` の直後でなければならない。`ABSPATH` チェック等のコードを `declare` と `namespace` の間に置くと fatal error。正しい順序: `<?php` → docblock → `declare(strict_types=1)` → `namespace` → `ABSPATH` チェック → `use` 文。docblock（コメント）は `declare` の前に置いても問題ない
- **secret ハッシュ化の互換性**: 1.0.0 の平文 `secret` と 1.0.1 の `secret_hash` (wp_hash_password) は `validate_credentials()` と `authenticate_basic()` の両方でレガシー互換対応済み。`$P$` / `$wp$` プレフィックスで判定

## 自己改善ログ

> ユーザーから指摘された失敗・判断ミス・非効率を記録し、同じ過ちを繰り返さないための学習記録。

- **2026-02-02**: コード変更時にバージョン番号の更新を忘れ、ユーザーに指摘された。複数ファイルにまたがるバージョン更新を「後で」と後回しにした結果。**教訓**: コード変更の最初のステップとしてバージョンを上げること。4箇所（plugin header, 定数, readme.txt, CLAUDE.md）を必ず同時に更新。grep で一致確認する習慣をつけろ
- **2026-02-01**: `declare(strict_types=1)` の移動時に `namespace` との順序制約を考慮せず、fatal error を発生させサイト全体をダウンさせた。PHPの `declare` + `namespace` の順序制約を正しく理解していなかった。**教訓**: PHPファイルの先頭構造を変更する際は必ず `php -l` で構文チェックを実行してからデプロイすること。正しい順序は `<?php` → docblock → `declare` → `namespace` → その他コード

## 更新履歴

| 日付 | バージョン | 内容 |
|------|-----------|------|
| 2026-02-02 | docs | `docs/SAAS_INTEGRATION.md` を削除し、`docs/APP_INTEGRATION.md` を新規作成。接続URL方式、ローカルツールラッパー方式（海外IPアクセス制限対策）、全33アビリティ完全リファレンス、MCP クライアント実装例を記載 |
| 2026-02-02 | 1.0.2 → 1.1.0 | 複数アプリ連携対応。接続URL方式（アプリURL入力不要）。単一接続→複数名前付き連携。「SaaS」→「アプリ」UI変更。API キー上限5→20。レガシーデータ自動マイグレーション。接続状態ポーリング REST エンドポイント追加 |
| 2026-02-01 | 1.0.1 → 1.0.2 | `declare(strict_types=1)` を全 saas-auth ファイルでファイル先頭に移動（PHP fatal error 修正） |
| 2026-02-01 | 1.0.0 → 1.0.1 | セキュリティ＆バグ修正17件。API secret ハッシュ化、introspect認証追加、デバッグエンドポイント制限、各ツールの出力修正、CJKキーワード密度修正、OAuth メタデータ整理、authenticate_basic() の secret_hash 対応 |

---

> ## ⚠️ 最終リマインダー: このファイルを更新したか？ ⚠️
>
> **セッション終了前チェックリスト:**
> - [ ] 今回変更したファイルの情報は記録したか？
> - [ ] 新しく発見したバグ・知見は記録したか？
> - [ ] バージョン番号を変更した場合、このファイル内の全バージョン記述を更新したか？
> - [ ] Troubleshooting Log に新しい踏んだ罠はないか？
> - [ ] 自己改善ログにユーザーからの指摘は記録したか？
>
> **更新を忘れるな。次のセッションの自分はこのファイルしか読めない。**
