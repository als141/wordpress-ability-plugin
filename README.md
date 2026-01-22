# WordPress MCP Ability Suite

WordPress サイトを AI エージェントに公開するための Model Context Protocol (MCP) サーバープラグインです。

## 概要

このプラグインは、WordPress の投稿・メディア・タクソノミーなどのコンテンツ操作を MCP ツールとして提供します。Claude、ChatGPT、その他の MCP 対応 AI エージェントから WordPress サイトを操作できます。

## 要件

- PHP 8.1 以上
- WordPress 6.0 以上

## インストール

1. `wordpress-mcp-ability-plugin-x.x.x.zip` をダウンロード
2. WordPress 管理画面 → プラグイン → 新規追加 → プラグインのアップロード
3. ZIP ファイルをアップロードして有効化
4. 設定 → MCP 連携 から SaaS と連携

## SaaS 連携（ワンクリック）

### ユーザー向け手順

1. プラグインを有効化
2. WordPress 管理画面 → **設定 → MCP 連携**
3. SaaS の URL を入力
4. **「SaaS と連携する」** ボタンをクリック
5. SaaS サイトで連携を承認
6. 自動的に WordPress に戻り、連携完了

連携解除は同じ画面から「連携を解除する」ボタンで行えます。

---

## SaaS 開発者向け：連携フロー実装

### 連携フローの概要

```
┌──────────────────┐         ┌──────────────────┐
│   WordPress      │         │     SaaS         │
│   プラグイン      │         │   サービス       │
└────────┬─────────┘         └────────┬─────────┘
         │                            │
         │ 1. ユーザーが「連携」クリック │
         ├───────────────────────────>│
         │  (registration_code 付き)   │
         │                            │
         │ 2. SaaS が register API 呼出│
         │<───────────────────────────┤
         │  POST /wp-mcp/v1/register   │
         │                            │
         │ 3. credentials 返却        │
         ├───────────────────────────>│
         │  (access_token, api_key)   │
         │                            │
         │ 4. コールバックでリダイレクト │
         │<───────────────────────────┤
         │  ?status=success           │
         │                            │
         │ 5. MCP 通信開始            │
         │<─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─ ─┤
         │                            │
```

### 1. 連携リクエスト受信

ユーザーが「SaaS と連携する」をクリックすると、SaaS の以下の URL にリダイレクトされます：

```
https://your-saas.com/connect/wordpress?
  action=wordpress_mcp_connect&
  site_url=https://example.com&
  site_name=My%20WordPress%20Site&
  mcp_endpoint=https://example.com/wp-json/mcp/mcp-adapter-default-server&
  register_endpoint=https://example.com/wp-json/wp-mcp/v1/register&
  registration_code=xxxxxxxxxxxx&
  callback_url=https://example.com/wp-json/wp-mcp/v1/connection-callback
```

### 2. クレデンシャル取得 API

SaaS は `register_endpoint` に POST リクエストを送信してクレデンシャルを取得します：

```bash
POST /wp-json/wp-mcp/v1/register
Content-Type: application/json

{
  "registration_code": "xxxxxxxxxxxx",
  "saas_identifier": "Your SaaS Name"
}
```

**レスポンス（成功）:**

```json
{
  "success": true,
  "mcp_endpoint": "https://example.com/wp-json/mcp/mcp-adapter-default-server",
  "access_token": "永久有効なアクセストークン",
  "api_key": "mcp_xxxxxxxxxx",
  "api_secret": "xxxxxxxxxxxxxxxxxxxxxxxx",
  "site_url": "https://example.com",
  "site_name": "My WordPress Site"
}
```

- `access_token`: Bearer トークンとして MCP リクエストに使用（**永久有効**）
- `api_key` / `api_secret`: Basic 認証でも使用可能

### 3. コールバック

クレデンシャル取得後、ユーザーを `callback_url` にリダイレクトします：

**成功時:**
```
GET /wp-json/wp-mcp/v1/connection-callback?status=success
```

**失敗時:**
```
GET /wp-json/wp-mcp/v1/connection-callback?status=error&error=エラーメッセージ
```

### 4. MCP 通信

取得した `access_token` を使用して MCP 通信を開始：

```bash
# セッション初期化
curl -X POST https://example.com/wp-json/mcp/mcp-adapter-default-server \
  -H "Authorization: Bearer {access_token}" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"initialize","params":{...},"id":1}'

# ツール呼び出し（Mcp-Session-Id ヘッダー必須）
curl https://example.com/wp-json/mcp/mcp-adapter-default-server \
  -H "Authorization: Bearer {access_token}" \
  -H "Mcp-Session-Id: {session_id}" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/call","params":{...},"id":2}'
```

---

## 利用可能な MCP ツール（25個）

### 投稿操作

| ツール名 | 説明 |
|---------|------|
| `wp-mcp-get-posts-by-category` | カテゴリ別投稿取得 |
| `wp-mcp-get-post-block-structure` | 投稿のブロック構造取得 |
| `wp-mcp-get-post-raw-content` | 投稿の生コンテンツ取得 |
| `wp-mcp-create-draft-post` | 新規下書き作成 |
| `wp-mcp-update-post-content` | 投稿コンテンツ更新 |
| `wp-mcp-update-post-meta` | 投稿メタ更新 |
| `wp-mcp-publish-post` | 投稿の公開 |
| `wp-mcp-delete-post` | 投稿削除 |

### メディア操作

| ツール名 | 説明 |
|---------|------|
| `wp-mcp-get-media-library` | メディアライブラリ取得 |
| `wp-mcp-upload-media` | メディアアップロード |
| `wp-mcp-set-featured-image` | アイキャッチ設定 |

### タクソノミー操作

| ツール名 | 説明 |
|---------|------|
| `wp-mcp-get-categories` | カテゴリ一覧取得 |
| `wp-mcp-get-tags` | タグ一覧取得 |
| `wp-mcp-create-term` | カテゴリ・タグ作成 |

### テーマ・ブロック

| ツール名 | 説明 |
|---------|------|
| `wp-mcp-get-theme-styles` | テーマスタイル取得 |
| `wp-mcp-get-block-patterns` | ブロックパターン取得 |
| `wp-mcp-get-reusable-blocks` | 再利用ブロック取得 |
| `wp-mcp-extract-used-blocks` | 使用ブロック抽出 |
| `wp-mcp-analyze-category-format-patterns` | カテゴリフォーマット分析 |

### 検証・情報

| ツール名 | 説明 |
|---------|------|
| `wp-mcp-validate-block-content` | ブロックコンテンツ検証 |
| `wp-mcp-check-regulation-compliance` | レギュレーション準拠チェック |
| `wp-mcp-check-seo-requirements` | SEO 要件チェック |
| `wp-mcp-get-article-regulations` | 記事レギュレーション取得 |
| `wp-mcp-get-site-info` | サイト情報取得 |
| `wp-mcp-get-post-types` | 投稿タイプ一覧取得 |

---

## MCP リソース（4個）

| リソース URI | 説明 |
|-------------|------|
| `wordpress://mcp/block_schemas` | ブロックスキーマ |
| `wordpress://mcp/style_guide` | スタイルガイド |
| `wordpress://mcp/category_templates` | カテゴリテンプレート |
| `wordpress://mcp/writing_regulations` | 執筆規約 |

---

## MCP プロンプト（4個）

| プロンプト名 | 説明 |
|------------|------|
| `wp-mcp-article-generation` | 記事生成プロンプト |
| `wp-mcp-format-conversion` | フォーマット変換プロンプト |
| `wp-mcp-seo-optimization` | SEO 最適化プロンプト |
| `wp-mcp-regulation-learning` | 規約学習プロンプト |

---

## セキュリティ

- アクセストークンは永久有効（連携解除で無効化）
- HTTPS 接続推奨
- 監査ログで認証試行を記録
- 連携解除時にすべてのトークンを無効化

---

## ライセンス

GPL v2 or later
