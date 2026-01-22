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

## 認証方法

このプラグインは2つの認証モードをサポートしています。

---

### 方法1: Application Password（デフォルト・推奨）

WordPress 標準の Application Password を使用します。SaaS認証を有効にしない場合、この方法が使用されます。

#### 設定手順

1. WordPress 管理画面 → ユーザー → プロフィール
2. 「アプリケーションパスワード」セクションまでスクロール
3. 新しいアプリケーション名を入力（例: "MCP Client"）
4. 「新しいアプリケーションパスワードを追加」をクリック
5. 生成されたパスワードを安全に保存

#### 接続方法

```bash
# MCP エンドポイント
https://your-site.com/wp-json/mcp/mcp-adapter-default-server

# 認証ヘッダー
Authorization: Basic base64(username:application_password)
```

#### Claude Desktop での設定例

`claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "npx",
      "args": [
        "mcp-remote",
        "https://your-site.com/wp-json/mcp/mcp-adapter-default-server",
        "--header",
        "Authorization: Basic dXNlcm5hbWU6YXBwbGljYXRpb25fcGFzc3dvcmQ="
      ]
    }
  }
}
```

> **注意**: `dXNlcm5hbWU6YXBwbGljYXRpb25fcGFzc3dvcmQ=` は `username:application_password` を Base64 エンコードした値です。

#### Base64 エンコード方法

```bash
# Linux/Mac
echo -n "username:xxxx xxxx xxxx xxxx xxxx xxxx" | base64

# Windows (PowerShell)
[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes("username:xxxx xxxx xxxx xxxx xxxx xxxx"))
```

---

### 方法2: SaaS 認証（OAuth 2.0 / API キー）

外部 SaaS サービスからの接続を想定した認証方式です。API キー、JWT トークン、OAuth 2.0 をサポートします。

#### 有効化

1. WordPress 管理画面 → 設定 → MCP SaaS
2. 「SaaS認証を有効にする」にチェック
3. 必要に応じて設定を調整
4. 「変更を保存」

> **重要**: SaaS認証を有効にすると、Application Password での Basic 認証は API キー認証として解釈されます。既存の Application Password 接続を維持したい場合は、SaaS認証を無効のままにしてください。

#### API キーの生成

1. 設定 → MCP SaaS → 「API キー管理」セクション
2. 「新しい API キーを生成」をクリック
3. 表示された API キーとシークレットを安全に保存

#### 認証方式

**Bearer Token（推奨）**

まずトークンエンドポイントでアクセストークンを取得:

```bash
curl -X POST https://your-site.com/wp-json/wp-mcp/v1/token \
  -H "Content-Type: application/json" \
  -d '{
    "grant_type": "client_credentials",
    "client_id": "YOUR_API_KEY",
    "client_secret": "YOUR_API_SECRET"
  }'
```

レスポンス:

```json
{
  "access_token": "xxxxxxxxxxxxxxxx",
  "token_type": "Bearer",
  "expires_in": 86400,
  "scope": "read write"
}
```

MCP リクエスト:

```bash
curl https://your-site.com/wp-json/mcp/mcp-adapter-default-server \
  -H "Authorization: Bearer xxxxxxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":1}'
```

**API Key 認証**

```bash
curl https://your-site.com/wp-json/mcp/mcp-adapter-default-server \
  -H "Authorization: ApiKey YOUR_API_KEY:HMAC_SIGNATURE" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":1}'
```

**Basic 認証（API キー）**

```bash
curl https://your-site.com/wp-json/mcp/mcp-adapter-default-server \
  -H "Authorization: Basic base64(API_KEY:API_SECRET)" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"tools/list","id":1}'
```

#### OAuth 2.0 メタデータエンドポイント

MCP 仕様に準拠した OAuth メタデータを提供:

```
GET /.well-known/oauth-protected-resource
GET /.well-known/oauth-authorization-server
GET /.well-known/mcp.json
```

---

## MCP エンドポイント

| エンドポイント | 説明 |
|---------------|------|
| `/wp-json/mcp/mcp-adapter-default-server` | MCP サーバー（メイン） |
| `/wp-json/wp-mcp/v1/token` | トークン取得（SaaS認証時） |
| `/wp-json/wp-mcp/v1/api-keys` | API キー管理（管理者のみ） |

---

## 利用可能な MCP ツール

### 投稿操作

| ツール名 | 説明 |
|---------|------|
| `wp-mcp/get-posts-by-category` | カテゴリ別投稿取得 |
| `wp-mcp/get-post-block-structure` | 投稿のブロック構造取得 |
| `wp-mcp/get-post-raw-content` | 投稿の生コンテンツ取得 |
| `wp-mcp/create-post` | 新規投稿作成 |
| `wp-mcp/update-post` | 投稿更新 |
| `wp-mcp/delete-post` | 投稿削除 |

### メディア操作

| ツール名 | 説明 |
|---------|------|
| `wp-mcp/upload-media` | メディアアップロード |
| `wp-mcp/get-media` | メディア取得 |
| `wp-mcp/delete-media` | メディア削除 |

### タクソノミー操作

| ツール名 | 説明 |
|---------|------|
| `wp-mcp/get-categories` | カテゴリ一覧取得 |
| `wp-mcp/get-tags` | タグ一覧取得 |
| `wp-mcp/create-category` | カテゴリ作成 |
| `wp-mcp/create-tag` | タグ作成 |

### テーマ・ブロック

| ツール名 | 説明 |
|---------|------|
| `wp-mcp/get-theme-styles` | テーマスタイル取得 |
| `wp-mcp/get-block-patterns` | ブロックパターン取得 |
| `wp-mcp/extract-used-blocks` | 使用ブロック抽出 |
| `wp-mcp/analyze-category-format-patterns` | カテゴリフォーマット分析 |

---

## MCP リソース

| リソース URI | 説明 |
|-------------|------|
| `wp-mcp://block-schema` | ブロックスキーマ |
| `wp-mcp://style-guide` | スタイルガイド |
| `wp-mcp://category-templates` | カテゴリテンプレート |
| `wp-mcp://writing-regulations` | 執筆規約 |

---

## MCP プロンプト

| プロンプト名 | 説明 |
|------------|------|
| `wp-mcp/generate-article` | 記事生成プロンプト |
| `wp-mcp/convert-format` | フォーマット変換プロンプト |
| `wp-mcp/optimize-seo` | SEO 最適化プロンプト |
| `wp-mcp/learn-regulations` | 規約学習プロンプト |

---

## SaaS 認証設定オプション

| 設定項目 | デフォルト | 説明 |
|---------|-----------|------|
| SaaS認証を有効にする | OFF | SaaS認証モードを有効化 |
| HTTPS を必須にする | ON | 本番環境では ON 推奨 |
| JWT シークレット | - | JWT トークン署名用の秘密鍵 |
| JWT 発行者 | - | JWT の iss クレーム |
| トークン有効期限 | 24時間 | アクセストークンの有効期限 |
| レート制限 | ON | リクエスト制限を有効化 |
| リクエスト上限 | 100 | ウィンドウ内の最大リクエスト数 |
| 制限ウィンドウ | 3600秒 | レート制限のウィンドウ期間 |
| 監査ログ | ON | 認証試行のログ記録 |

---

## トラブルシューティング

### 「401 Unauthorized」エラー

1. Application Password が正しく生成されているか確認
2. Base64 エンコードが正しいか確認
3. ユーザー名にスペースや特殊文字が含まれていないか確認

### 「403 Forbidden」エラー

1. SaaS認証で HTTPS が必須になっていないか確認
2. ユーザーに適切な権限があるか確認

### MCP ツールが表示されない

1. プラグインが有効化されているか確認
2. パーマリンク設定を保存し直す（設定 → パーマリンク → 変更を保存）

---

## ライセンス

GPL v2 or later

## サポート

- GitHub Issues: https://github.com/your-repo/wordpress-mcp-ability-plugin/issues
