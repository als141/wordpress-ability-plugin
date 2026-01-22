# WordPress.org 公式プラグインストア公開ガイド

このドキュメントでは、WordPress.org 公式プラグインディレクトリにプラグインを公開するための完全な手順を説明します。

---

## 目次

1. [公開前の確認事項](#1-公開前の確認事項)
2. [必要なファイルの準備](#2-必要なファイルの準備)
3. [readme.txt の作成](#3-readmetxt-の作成)
4. [プラグイン提出](#4-プラグイン提出)
5. [審査プロセス](#5-審査プロセス)
6. [承認後の SVN 操作](#6-承認後の-svn-操作)
7. [アップデートのリリース](#7-アップデートのリリース)
8. [よくあるリジェクト理由と対策](#8-よくあるリジェクト理由と対策)
9. [このプラグイン固有の課題](#9-このプラグイン固有の課題)

---

## 1. 公開前の確認事項

### 1.1 WordPress.org プラグインガイドライン準拠チェック

| チェック項目 | 必須 | 状態 |
|-------------|------|------|
| GPL 互換ライセンス | Yes | - |
| readme.txt ファイル | Yes | - |
| セキュリティ対策（サニタイズ、エスケープ） | Yes | - |
| 外部サービス接続の明示 | Yes | - |
| トライアル/制限機能なし | Yes | - |
| コードの難読化なし | Yes | - |
| WordPress コーディング標準準拠 | 推奨 | - |
| 国際化対応（i18n） | 推奨 | - |
| 2FA 有効化（WordPress.org アカウント） | Yes | - |

### 1.2 アカウント要件

1. **WordPress.org アカウント作成**
   - https://wordpress.org/support/register.php で登録
   - メールアドレスの認証を完了

2. **2FA（二要素認証）の有効化**
   - 2024年後半から必須化
   - WordPress.org プロフィール設定で有効化

3. **メールのホワイトリスト登録**
   - `plugins@wordpress.org` からのメールを受信できるようにする

---

## 2. 必要なファイルの準備

### 2.1 必須ファイル

```
your-plugin/
├── your-plugin.php          # メインプラグインファイル（必須）
├── readme.txt               # プラグイン説明ファイル（必須）
├── LICENSE                  # ライセンスファイル（推奨）
├── includes/                # PHP ファイル
├── assets/                  # CSS/JS（プラグイン用）
└── languages/               # 翻訳ファイル（推奨）
```

### 2.2 SVN 用 assets フォルダ（別管理）

```
assets/                      # SVN の /assets/ ディレクトリ用
├── icon-128x128.png         # プラグインアイコン（128x128）
├── icon-256x256.png         # プラグインアイコン（256x256）
├── banner-772x250.png       # バナー画像
├── banner-1544x500.png      # Retina バナー画像
├── screenshot-1.png         # スクリーンショット
├── screenshot-2.png
└── screenshot-3.png
```

### 2.3 メインプラグインファイルのヘッダー

```php
<?php
/**
 * Plugin Name: Your Plugin Name
 * Plugin URI: https://example.com/plugin
 * Description: A brief description of your plugin (150 characters max for display).
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: your-plugin
 * Domain Path: /languages
 */
```

**重要**: WordPress 5.8 以降、`Requires at least` と `Requires PHP` はメインプラグインファイルから読み取られます。

---

## 3. readme.txt の作成

### 3.1 基本構造

```
=== Your Plugin Name ===
Contributors: yourusername
Donate link: https://example.com/donate
Tags: tag1, tag2, tag3, tag4, tag5
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Short description of the plugin (150 characters max). No markup allowed.

== Description ==

This is the long description. You can use **Markdown** here.

**Features:**

* Feature 1
* Feature 2
* Feature 3

**External Services:**

This plugin connects to [Service Name](https://example.com) for [purpose].
- [Terms of Service](https://example.com/terms)
- [Privacy Policy](https://example.com/privacy)

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/your-plugin/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure the plugin at Settings → Your Plugin

== Frequently Asked Questions ==

= Question 1? =

Answer 1.

= Question 2? =

Answer 2.

== Screenshots ==

1. Screenshot description 1
2. Screenshot description 2
3. Screenshot description 3

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release of the plugin.
```

### 3.2 重要なフィールド説明

| フィールド | 説明 | 注意点 |
|-----------|------|--------|
| `Contributors` | WordPress.org ユーザー名（カンマ区切り） | 実在するユーザー名のみ |
| `Tags` | 検索用タグ | **最大5個**が表示される |
| `Requires at least` | 最低 WordPress バージョン | 数字のみ（例: 6.0） |
| `Tested up to` | テスト済み最新バージョン | 最新安定版を推奨 |
| `Stable tag` | 安定版のタグ名 | `/tags/` のフォルダ名と一致させる |
| `Requires PHP` | 最低 PHP バージョン | |

### 3.3 外部サービス接続の明示（必須）

外部サービスに接続する場合、**必ず以下を明記**：

```
== Description ==

**Third-Party Services:**

This plugin connects to the following external services:

1. **[Service Name]** - Used for [purpose]
   - Service URL: https://example.com
   - Terms of Service: https://example.com/terms
   - Privacy Policy: https://example.com/privacy

Data transmitted: [what data is sent]
When transmitted: [when the connection occurs]
```

### 3.4 readme.txt バリデーション

提出前に必ず公式バリデーターでチェック：

- **Readme Validator**: https://wordpress.org/plugins/developers/readme-validator/
- **Readme Generator**: https://developer.wordpress.org/plugins/wordpress-org/readme-generator/

---

## 4. プラグイン提出

### 4.1 提出手順

1. **WordPress.org にログイン**
   - https://wordpress.org/support/

2. **プラグイン提出ページにアクセス**
   - https://wordpress.org/plugins/developers/add/

3. **必要情報を入力**
   - プラグイン名
   - プラグインの説明（英語）
   - プラグインの ZIP ファイルをアップロード

4. **ガイドライン同意**
   - プラグインガイドラインへの同意にチェック

5. **提出**
   - 「Submit」ボタンをクリック

### 4.2 ZIP ファイルの作成

```bash
# プラグインディレクトリに移動
cd /path/to/your-plugin

# vendor ディレクトリを含める場合（Composer 依存がある場合）
composer install --no-dev --optimize-autoloader

# ZIP 作成（不要ファイルを除外）
zip -r your-plugin.zip . \
  -x "*.git*" \
  -x "*.github*" \
  -x "*node_modules*" \
  -x "*.DS_Store" \
  -x "composer.lock" \
  -x "phpunit.xml" \
  -x "tests/*" \
  -x ".env*"
```

---

## 5. 審査プロセス

### 5.1 タイムライン

| フェーズ | 期間 |
|---------|------|
| 初回審査 | 約 **14営業日** |
| 修正要求への対応 | 適宜 |
| 再審査 | 数日 |

### 5.2 審査で確認される項目

1. **セキュリティ**
   - 入力のサニタイズ（`sanitize_*` 関数）
   - 出力のエスケープ（`esc_*` 関数）
   - Nonce 検証
   - 権限チェック（`current_user_can()`）

2. **コード品質**
   - 直接ファイルアクセスの防止（`defined('ABSPATH')`）
   - WordPress 標準関数の使用
   - プレフィックスの使用（関数名、クラス名、オプション名）

3. **ガイドライン遵守**
   - ライセンス互換性
   - 外部サービスの明示
   - トラッキングなし（ユーザー同意なし）

### 5.3 審査結果の通知

- **承認**: SVN リポジトリのアクセス情報がメールで届く
- **修正要求**: 修正が必要な箇所がメールで通知される
- **リジェクト**: 理由とともにリジェクト通知が届く

---

## 6. 承認後の SVN 操作

### 6.1 SVN クライアントのインストール

```bash
# macOS
brew install svn

# Ubuntu/Debian
sudo apt-get install subversion

# Windows
# TortoiseSVN をダウンロード: https://tortoisesvn.net/
```

### 6.2 リポジトリ構造

```
https://plugins.svn.wordpress.org/your-plugin/
├── assets/          # アイコン、バナー、スクリーンショット
├── trunk/           # 開発版コード
└── tags/            # リリースバージョン
    ├── 1.0.0/
    ├── 1.0.1/
    └── 1.1.0/
```

### 6.3 初回アップロード手順

```bash
# 1. リポジトリをチェックアウト
svn co https://plugins.svn.wordpress.org/your-plugin your-plugin-svn
cd your-plugin-svn

# 2. trunk にプラグインファイルをコピー
cp -r /path/to/your-plugin/* trunk/

# 3. ファイルを SVN に追加
svn add trunk/*

# 4. コミット
svn ci -m "Initial release version 1.0.0" --username your-wp-username

# 5. タグを作成
svn cp trunk tags/1.0.0

# 6. タグをコミット
svn ci -m "Tagging version 1.0.0" --username your-wp-username

# 7. assets をアップロード（アイコン、スクリーンショット等）
cp /path/to/assets/* assets/
svn add assets/*
svn ci -m "Adding plugin assets" --username your-wp-username
```

### 6.4 重要な注意事項

| 注意点 | 説明 |
|--------|------|
| **trunk にサブフォルダを作らない** | `trunk/my-plugin/my-plugin.php` は NG |
| **ZIP ファイルをアップロードしない** | 個別ファイルのみ |
| **vendor は含めてOK** | Composer 依存がある場合 |
| **.git は含めない** | 不要ファイルは除外 |
| **Stable tag を更新** | タグ作成後、trunk/readme.txt を更新 |

---

## 7. アップデートのリリース

### 7.1 バージョンアップ手順

```bash
# 1. リポジトリを最新に更新
cd your-plugin-svn
svn up

# 2. trunk のファイルを更新
cp -r /path/to/updated-plugin/* trunk/

# 3. 新しいファイルを追加（あれば）
svn add trunk/new-file.php

# 4. 削除されたファイルを削除（あれば）
svn delete trunk/old-file.php

# 5. trunk をコミット
svn ci -m "Update to version 1.1.0" --username your-wp-username

# 6. 新しいタグを作成
svn cp trunk tags/1.1.0

# 7. タグをコミット
svn ci -m "Tagging version 1.1.0" --username your-wp-username
```

### 7.2 readme.txt の Stable tag 更新

```diff
- Stable tag: 1.0.0
+ Stable tag: 1.1.0
```

**重要**: trunk/readme.txt の `Stable tag` を更新しないと、WordPress.org に新バージョンが反映されません。

### 7.3 自動更新の仕組み

WordPress は以下の情報を比較して更新を検出：

1. サイトにインストールされているプラグインの `Version`（メインPHPファイル）
2. WordPress.org の `tags/{Stable tag}/` 内のプラグインの `Version`

両者が異なる場合、更新通知が表示されます。

---

## 8. よくあるリジェクト理由と対策

### 8.1 セキュリティ関連

| 問題 | 対策 |
|------|------|
| 入力がサニタイズされていない | `sanitize_text_field()`, `absint()`, `wp_kses()` を使用 |
| 出力がエスケープされていない | `esc_html()`, `esc_attr()`, `esc_url()` を使用 |
| Nonce 検証がない | `wp_nonce_field()` と `wp_verify_nonce()` を使用 |
| 権限チェックがない | `current_user_can()` を使用 |
| 直接ファイルアクセス可能 | `defined('ABSPATH') or exit;` を追加 |

### 8.2 ガイドライン違反

| 問題 | 対策 |
|------|------|
| 外部サービス未明示 | readme.txt に詳細を記載 |
| トラッキング（ユーザー同意なし） | 削除またはオプトイン化 |
| 商標の不正使用 | プラグイン名から削除 |
| 難読化されたコード | 元のソースコードも含める |
| 有料機能へのアップセル過剰 | 控えめな表示に変更 |

### 8.3 コード品質

| 問題 | 対策 |
|------|------|
| WordPress 関数の不使用 | `wp_remote_get()` 等を使用 |
| プレフィックスなし | 全ての関数/クラスにプレフィックス追加 |
| 直接 DB クエリ | `$wpdb->prepare()` を使用 |
| `eval()` の使用 | 削除 |

---

## 9. このプラグイン固有の課題

### 9.1 現状の問題点

このプラグイン（WordPress MCP Ability Suite）を WordPress.org に公開するには、以下の課題があります：

| 課題 | 重要度 | 対応状況 |
|------|--------|----------|
| readme.txt が存在しない | **重大** | 未対応 |
| MU プラグインの自動生成 | **重大** | 要修正 |
| 実験段階の依存関係 | **高** | 待機 |
| Author 情報が "Example" | **中** | 要修正 |
| 外部通信の明示不足 | **中** | 要追加 |

### 9.2 依存関係の問題

```
wordpress/abilities-api v0.4.0  → 実験段階
wordpress/mcp-adapter   0.1.0   → 非常に初期段階
```

これらは WordPress 公式プロジェクトですが、まだコアに統合されておらず、安定版前の状態です。

### 9.3 推奨される対応

**短期的（WordPress.org 以外での公開）:**

1. GitHub Releases + [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) で自動更新を実装

**中長期的（WordPress.org での公開を目指す場合）:**

1. readme.txt を作成
2. MU プラグイン自動生成をオプション化（ユーザー同意必須）
3. Author 情報を修正
4. 外部通信がある場合は明示
5. Abilities API が安定版になるのを待つ

### 9.4 readme.txt テンプレート（このプラグイン用）

```
=== WordPress MCP Ability Suite ===
Contributors: your-username
Tags: ai, mcp, abilities, content, automation
Requires at least: 6.0
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered content management tools for WordPress using the Model Context Protocol.

== Description ==

WordPress MCP Ability Suite provides AI abilities for content operations, including:

* Content analysis and pattern extraction
* Draft creation with Gutenberg blocks
* SEO validation and optimization
* Media library management
* Taxonomy management

**Requirements:**

* PHP 8.0 or higher
* WordPress 6.0 or higher

**Dependencies:**

This plugin bundles the following packages:
* wordpress/abilities-api (GPL-2.0-or-later)
* wordpress/mcp-adapter (GPL-2.0-or-later)
* automattic/jetpack-autoloader (GPL-2.0-or-later)

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/wp-mcp-ability-suite/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. The plugin will automatically register AI abilities

== Frequently Asked Questions ==

= What is MCP? =

MCP (Model Context Protocol) is a standard for AI model integration.

= Does this plugin connect to external services? =

No. All operations are performed locally within your WordPress installation.

== Screenshots ==

1. Ability categories overview
2. Content analysis tools
3. Draft creation interface

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release of WordPress MCP Ability Suite.
```

---

## 参考リンク

- [Plugin Handbook](https://developer.wordpress.org/plugins/)
- [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
- [How Your readme.txt Works](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/)
- [Using Subversion](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/)
- [Plugin Developer FAQ](https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/)
- [Readme Validator](https://wordpress.org/plugins/developers/readme-validator/)
- [Plugin Submission Page](https://wordpress.org/plugins/developers/add/)
