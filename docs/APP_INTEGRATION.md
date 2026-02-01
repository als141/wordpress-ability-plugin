# アプリ連携実装ガイド

このドキュメントでは、WordPress MCP Ability Suite プラグイン (v1.1.1) と連携する外部アプリケーションの実装方法を説明します。

## 目次

1. [概要](#概要)
2. [連携フロー](#連携フロー)
3. [登録 API](#登録-api)
4. [MCP 通信の基本](#mcp-通信の基本)
5. [ローカルツールとして定義する (重要)](#ローカルツールとして定義する-重要)
6. [MCP クライアント実装例](#mcp-クライアント実装例)
7. [全ツールリファレンス (25個)](#全ツールリファレンス-25個)
8. [全リソースリファレンス (4個)](#全リソースリファレンス-4個)
9. [全プロンプトリファレンス (4個)](#全プロンプトリファレンス-4個)
10. [データベース設計](#データベース設計)
11. [セキュリティ考慮事項](#セキュリティ考慮事項)
12. [エラーハンドリング](#エラーハンドリング)
13. [トラブルシューティング](#トラブルシューティング)

---

## 概要

WordPress MCP Ability Suite は、外部アプリケーションが WordPress サイトのコンテンツを MCP (Model Context Protocol) 経由で操作できる仕組みを提供します。

### 連携の特徴

- **接続URL方式**: WordPress 管理画面で生成した URL をアプリに貼り付けるだけで連携完了
- **複数連携対応**: 複数のアプリ、または同一アプリの複数ユーザーがそれぞれ独立して連携可能
- **セキュリティ**: 一時的な登録コード (10分有効、ワンタイム) による安全なクレデンシャル交換
- **永続トークン**: 発行されたアクセストークンは期限切れしない (管理者が手動で解除するまで有効)
- **33のアビリティ**: 25 ツール + 4 リソース + 4 プロンプト

### 前提条件

- アプリは HTTPS でホストされていること (推奨)
- アプリから WordPress サイトへ HTTP リクエストを発行できること
- WordPress サイトの REST API が公開されていること

---

## 連携フロー

### 接続URL方式

WordPress 管理者が「接続URL」を生成し、その URL をアプリに入力することで連携します。

### シーケンス図

```
┌────────────┐     ┌────────────┐     ┌────────────┐
│   Admin    │     │ WordPress  │     │    App     │
│  Browser   │     │   Plugin   │     │  Service   │
└─────┬──────┘     └─────┬──────┘     └─────┬──────┘
      │                  │                  │
      │ 1. 連携ネームを入力                  │
      │    「接続URLを生成する」クリック       │
      ├─────────────────>│                  │
      │                  │                  │
      │ 2. registration_code 生成            │
      │    (10分有効, ワンタイム)              │
      │                  │                  │
      │ 3. 接続URL を表示                    │
      │<─────────────────┤                  │
      │                  │                  │
      │ 4. 管理者が接続URLをコピー            │
      │    → アプリに貼り付け                 │
      │                  │                  │
      │                  │ 5. アプリが接続URLを解析
      │                  │    → register API 呼出
      │                  │<─────────────────┤
      │                  │ POST /register   │
      │                  │                  │
      │                  │ 6. credentials 返却
      │                  ├─────────────────>│
      │                  │                  │
      │                  │ 7. 認証情報を保存 │
      │                  │                  │
      │ 8. 管理画面が自動検知                 │
      │    (AJAX ポーリング)                  │
      │    「連携完了」表示                    │
      │                  │                  │
      │                  │ 9. MCP 通信開始  │
      │                  │<=================>
      │                  │                  │
```

### フロー詳細

| ステップ | アクター | 説明 |
|---------|---------|------|
| 1 | 管理者 | WordPress 管理画面 (設定 > MCP 連携) で連携ネームを入力し「接続URLを生成する」をクリック |
| 2 | WordPress | 一時的な `registration_code` (64文字英数字、10分有効) を生成・保存 |
| 3 | WordPress | 接続URL を管理画面に表示。形式: `{register_endpoint}?code={registration_code}` |
| 4 | 管理者 | 接続URL をコピーし、アプリの設定画面に貼り付ける |
| 5 | アプリ | 接続URL を解析し、`/wp-mcp/v1/register` に POST リクエストを送信 |
| 6 | WordPress | 登録コードを検証し、クレデンシャル (access_token, api_key, api_secret) を返却 |
| 7 | アプリ | 受け取った認証情報をデータベースに暗号化保存 |
| 8 | WordPress | 管理画面が AJAX ポーリングで連携完了を自動検知、UI を更新 |
| 9 | アプリ | 保存した認証情報で MCP 通信を開始 |

---

## 登録 API

### 接続URL の形式

WordPress が生成する接続URL:

```
https://example.com/wp-json/wp-mcp/v1/register?code=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

| 部分 | 説明 |
|------|------|
| `https://example.com/wp-json/wp-mcp/v1/register` | 登録エンドポイント |
| `code` | 登録コード (64文字の英数字) |

### 接続URL の解析

アプリは接続URL から以下の情報を抽出します:

```python
from urllib.parse import urlparse, parse_qs

def parse_connection_url(connection_url: str) -> dict:
    """接続URL を解析して必要な情報を抽出する。"""
    parsed = urlparse(connection_url)
    query = parse_qs(parsed.query)

    code = query.get("code", [None])[0]
    if not code:
        raise ValueError("接続URLに登録コードが含まれていません")

    # クエリパラメータを除いた登録エンドポイント
    register_endpoint = f"{parsed.scheme}://{parsed.netloc}{parsed.path}"

    # サイト URL を推測
    path = parsed.path
    wp_json_index = path.find("/wp-json/")
    site_path = path[:wp_json_index] if wp_json_index >= 0 else ""
    site_url = f"{parsed.scheme}://{parsed.netloc}{site_path}"

    # MCP エンドポイントを構築
    mcp_endpoint = f"{site_url}/wp-json/mcp/mcp-adapter-default-server"

    return {
        "registration_code": code,
        "register_endpoint": register_endpoint,
        "site_url": site_url,
        "mcp_endpoint": mcp_endpoint,
    }
```

### 登録リクエスト

```http
POST /wp-json/wp-mcp/v1/register
Content-Type: application/json

{
  "registration_code": "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "saas_identifier": "My App Name"
}
```

| パラメータ | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `registration_code` | string | Yes | 接続URL から抽出した登録コード (64文字) |
| `saas_identifier` | string | No | アプリの識別名 (WordPress 管理画面の「アプリ名」列に表示) |

### 成功レスポンス (200 OK)

```json
{
  "success": true,
  "mcp_endpoint": "https://example.com/wp-json/mcp/mcp-adapter-default-server",
  "access_token": "a1b2c3d4e5f6...",
  "api_key": "mcp_xxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "api_secret": "yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy",
  "site_url": "https://example.com",
  "site_name": "My WordPress Site",
  "connection_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

| フィールド | 型 | 説明 |
|-----------|-----|------|
| `success` | boolean | 常に `true` |
| `mcp_endpoint` | string | MCP サーバーの URL |
| `access_token` | string | Bearer 認証用アクセストークン (**永久有効**) |
| `api_key` | string | API キー (Basic 認証用) |
| `api_secret` | string | API シークレット (Basic 認証用) |
| `site_url` | string | WordPress サイトの URL |
| `site_name` | string | WordPress サイト名 |
| `connection_id` | string | 連携 UUID |

### エラーレスポンス

| ステータス | コード | 説明 |
|-----------|--------|------|
| 400 | `missing_code` | registration_code が未指定 |
| 401 | `invalid_code` | 無効な登録コード (使用済み含む) |
| 401 | `expired_code` | 登録コードの有効期限切れ (10分) |

### 登録コードの特性

- **ワンタイム**: 一度使用すると即座に無効化 (検証後に削除)
- **有効期限**: 生成から **10分間** のみ有効
- **ハッシュ保存**: WordPress 側では SHA-256 ハッシュで保存
- **長さ**: 64文字の英数字
- **リトライ不可**: エラー発生時、登録コードは無効化済み。新しい接続URL を生成するよう案内すること

---

## MCP 通信の基本

### プロトコル

- **仕様**: MCP 2025-06-18 (JSON-RPC 2.0 over Streamable HTTP)
- **メソッド**: `POST`
- **Content-Type**: `application/json`
- **エンドポイント**: レスポンスの `mcp_endpoint` フィールドに含まれる URL

### 認証方法

#### Bearer Token (推奨)

```http
Authorization: Bearer {access_token}
```

#### Basic Auth (代替)

```http
Authorization: Basic base64({api_key}:{api_secret})
```

### セッション管理

MCP 通信はセッションベースです。最初に `initialize` でセッションを開始し、以降のリクエストにセッション ID を付与します。

| 方向 | ヘッダー |
|------|---------|
| リクエスト (アプリ → WordPress) | `Mcp-Session-Id: {session_id}` |
| レスポンス (WordPress → アプリ) | `Mcp-Session-Id: {session_id}` |

**注意**: HTTP ヘッダー名は大文字小文字を区別しないため、ライブラリによっては `mcp-session-id` で返されます。両方に対応してください。

---

## ローカルツールとして定義する (重要)

### MCP リモート接続を使わない理由

OpenAI API や Claude API を使ってエージェントを構築する場合、MCP をリモートツールとして接続する方法がありますが、**これは推奨しません**。

**理由**:
- MCP リモート接続の場合、OpenAI / Anthropic のサーバーから直接 WordPress サイトに HTTP リクエストが送信される
- これは**海外サーバーからのアクセス**になるため、日本のホスティングサービスで**海外 IP アクセス制限**が有効な場合にブロックされる
- WAF (Web Application Firewall) が海外 IP を拒否する設定になっている場合も同様

### 推奨: ローカルツールラッパー方式

アプリのバックエンドサーバーに WordPress MCP ツールのラッパー関数を定義し、**アプリサーバー (日本国内) から WordPress に直接アクセス**する方式を推奨します。

```
[AI API (OpenAI/Claude)] → [アプリサーバー (日本)] → [WordPress サイト (日本)]
                              ↑ ここでツールを実行
```

この方式なら:
- アプリサーバーと WordPress 間は国内通信なのでアクセス制限に引っかからない
- AI API にはツールの入出力 (テキスト) だけが送信される
- 認証情報が AI API に渡ることがない

### 実装パターン (OpenAI Agents SDK / Python)

以下は実際の production 実装に基づくパターンです。

#### 1. MCP クライアント層

WordPress への HTTP 通信を担当:

```python
import httpx
import json

MCP_TIMEOUT = 60.0
MCP_LONG_TIMEOUT = 300.0  # メディアアップロード用

class WordPressMcpClient:
    def __init__(self, mcp_endpoint: str, access_token: str):
        self._url = mcp_endpoint
        self._auth = f"Bearer {access_token}"
        self._session_id = None
        self._request_id = 0

    async def initialize(self):
        """MCP セッションを開始"""
        self._request_id += 1
        async with httpx.AsyncClient(timeout=MCP_TIMEOUT) as client:
            response = await client.post(
                self._url,
                headers={
                    "Authorization": self._auth,
                    "Content-Type": "application/json",
                },
                json={
                    "jsonrpc": "2.0",
                    "method": "initialize",
                    "params": {
                        "protocolVersion": "2024-11-05",
                        "capabilities": {},
                        "clientInfo": {"name": "my-app", "version": "1.0.0"},
                    },
                    "id": self._request_id,
                },
            )
            if response.status_code != 200:
                raise Exception(f"MCP init failed: HTTP {response.status_code}")

            self._session_id = (
                response.headers.get("mcp-session-id")
                or response.headers.get("Mcp-Session-Id")
            )
            if not self._session_id:
                raise Exception("MCP session ID not found in response headers")

    async def call_tool(self, tool_name: str, args: dict, timeout: float = MCP_TIMEOUT) -> str:
        """MCP ツールを呼び出す"""
        if not self._session_id:
            await self.initialize()

        self._request_id += 1
        async with httpx.AsyncClient(timeout=timeout) as client:
            response = await client.post(
                self._url,
                headers={
                    "Authorization": self._auth,
                    "Content-Type": "application/json",
                    "Mcp-Session-Id": self._session_id,
                },
                json={
                    "jsonrpc": "2.0",
                    "method": "tools/call",
                    "params": {"name": tool_name, "arguments": args},
                    "id": self._request_id,
                },
            )
            data = response.json()

            if "error" in data:
                error = data["error"]
                # セッションエラーなら再接続
                if error.get("code") == -32600 or "session" in str(error.get("message", "")).lower():
                    self._session_id = None
                    await self.initialize()
                    return await self.call_tool(tool_name, args, timeout)
                raise Exception(f"MCP error: {error.get('message')}")

            result = data.get("result", {})
            # structuredContent があればそれを返す、なければ text
            if result.get("structuredContent"):
                return json.dumps(result["structuredContent"], ensure_ascii=False)
            content = result.get("content", [])
            if content and content[0].get("text"):
                return content[0]["text"]
            return json.dumps(result, ensure_ascii=False)
```

#### 2. ローカルツール定義層

MCP ツールを AI エージェントのローカル関数ツールとしてラップ:

```python
from agents import function_tool  # OpenAI Agents SDK

# グローバルなMCPクライアント (初期化は別途)
mcp_client: WordPressMcpClient = None

@function_tool
async def wp_get_site_info() -> str:
    """サイトの基本情報を取得します。"""
    return await mcp_client.call_tool("wp-mcp-get-site-info", {})

@function_tool
async def wp_get_posts_by_category(
    category_id: int,
    limit: int | None = None,
    order: str | None = None,
    orderby: str | None = None,
) -> str:
    """指定カテゴリの記事一覧を取得します。

    Args:
        category_id: カテゴリID
        limit: 取得件数（最大100、デフォルト10）
        order: 並び順 (DESC/ASC)
        orderby: ソート基準 (date/title/modified)
    """
    args = {"category_id": category_id}
    if limit is not None:
        args["limit"] = limit
    if order is not None:
        args["order"] = order
    if orderby is not None:
        args["orderby"] = orderby
    return await mcp_client.call_tool("wp-mcp-get-posts-by-category", args)

@function_tool
async def wp_create_draft_post(
    title: str,
    content: str,
    category_ids: list[int] | None = None,
    tag_ids: list[int] | None = None,
    excerpt: str | None = None,
) -> str:
    """GutenbergブロックHTMLで新規下書きを作成します。

    Args:
        title: 記事タイトル
        content: ブロックHTML形式のコンテンツ
        category_ids: カテゴリIDの配列
        tag_ids: タグIDの配列
        excerpt: 抜粋
    """
    args = {"title": title, "content": content}
    if category_ids is not None:
        args["category_ids"] = category_ids
    if tag_ids is not None:
        args["tag_ids"] = tag_ids
    if excerpt is not None:
        args["excerpt"] = excerpt
    return await mcp_client.call_tool("wp-mcp-create-draft-post", args)

# ... 他のツールも同様にラップ
```

#### 3. エージェントにツールを登録

```python
from agents import Agent

ALL_WORDPRESS_TOOLS = [
    wp_get_site_info,
    wp_get_posts_by_category,
    wp_create_draft_post,
    # ... 全ツールを列挙
]

agent = Agent(
    name="BlogWriter",
    instructions="あなたは WordPress ブログ記事を執筆する AI アシスタントです。...",
    model="gpt-4o",
    tools=ALL_WORDPRESS_TOOLS,
)
```

### ツール名の変換規則

MCP Adapter はツール名のスラッシュ (`/`) をハイフン (`-`) に変換します:

| WordPress プラグイン側 | MCP 通信時 (tools/call) |
|----------------------|------------------------|
| `wp-mcp/get-site-info` | `wp-mcp-get-site-info` |
| `wp-mcp/create-draft-post` | `wp-mcp-create-draft-post` |

ツールを呼び出す際は **ハイフン形式** (`wp-mcp-xxx`) を使用してください。

---

## MCP クライアント実装例

### Python (production 品質)

```python
import contextvars
import hashlib
import json
import logging
import httpx

logger = logging.getLogger(__name__)

MCP_TIMEOUT = 60.0
MCP_LONG_TIMEOUT = 300.0

# コンテキスト変数: エージェント実行中に site_id を伝播
_current_site_id = contextvars.ContextVar("_current_site_id", default=None)
_current_user_id = contextvars.ContextVar("_current_user_id", default=None)


class WordPressMcpClient:
    """WordPress MCP クライアント"""

    def __init__(self, mcp_endpoint: str, access_token: str):
        self._url = mcp_endpoint
        self._auth = f"Bearer {access_token}"
        self._session_id = None
        self._request_id = 0

    async def initialize(self):
        """MCP セッションを初期化"""
        self._request_id += 1
        async with httpx.AsyncClient(timeout=MCP_TIMEOUT) as client:
            response = await client.post(
                self._url,
                headers={
                    "Authorization": self._auth,
                    "Content-Type": "application/json",
                },
                json={
                    "jsonrpc": "2.0",
                    "method": "initialize",
                    "params": {
                        "protocolVersion": "2024-11-05",
                        "capabilities": {},
                        "clientInfo": {"name": "my-app", "version": "1.0.0"},
                    },
                    "id": self._request_id,
                },
            )

            if response.status_code != 200:
                raise Exception(f"MCP init HTTP {response.status_code}: {response.text[:500]}")

            data = response.json()
            if "error" in data:
                raise Exception(f"MCP init error: {data['error'].get('message')}")

            self._session_id = (
                response.headers.get("mcp-session-id")
                or response.headers.get("Mcp-Session-Id")
            )
            if not self._session_id:
                raise Exception("Mcp-Session-Id header not found")

            logger.info(f"MCP session established: {self._session_id[:8]}...")

    async def call_tool(self, tool_name: str, args: dict = None, timeout: float = MCP_TIMEOUT) -> str:
        """MCP ツールを呼び出す"""
        if not self._session_id:
            await self.initialize()

        self._request_id += 1
        args = args or {}

        async with httpx.AsyncClient(timeout=timeout) as client:
            response = await client.post(
                self._url,
                headers={
                    "Authorization": self._auth,
                    "Content-Type": "application/json",
                    "Mcp-Session-Id": self._session_id,
                },
                json={
                    "jsonrpc": "2.0",
                    "method": "tools/call",
                    "params": {"name": tool_name, "arguments": args},
                    "id": self._request_id,
                },
            )

            data = response.json()
            if "error" in data:
                error = data["error"]
                # セッションエラーなら再接続してリトライ
                if error.get("code") == -32600 or "session" in str(error.get("message", "")).lower():
                    logger.warning("MCP session error, reconnecting...")
                    self._session_id = None
                    await self.initialize()
                    return await self.call_tool(tool_name, args, timeout)
                raise Exception(f"MCP error ({error.get('code')}): {error.get('message')}")

            result = data.get("result", {})
            if result.get("structuredContent"):
                return json.dumps(result["structuredContent"], ensure_ascii=False)
            content = result.get("content", [])
            if content and content[0].get("text"):
                return content[0]["text"]
            return json.dumps(result, ensure_ascii=False)

    async def test_connection(self) -> dict:
        """接続テスト (4ステップ: クレデンシャル → 到達性 → 初期化 → ツール呼び出し)"""
        try:
            await self.initialize()
            result = await self.call_tool("wp-mcp-get-site-info", {})
            site = json.loads(result)
            return {"success": True, "site_name": site.get("name"), "site_url": site.get("url")}
        except Exception as e:
            return {"success": False, "error": str(e)}
```

### Node.js

```javascript
class WordPressMcpClient {
  constructor({ mcpEndpoint, accessToken }) {
    this.mcpEndpoint = mcpEndpoint;
    this.accessToken = accessToken;
    this.sessionId = null;
    this.requestId = 0;
  }

  async connect() {
    const response = await fetch(this.mcpEndpoint, {
      method: 'POST',
      headers: this._getHeaders(),
      body: JSON.stringify({
        jsonrpc: '2.0',
        method: 'initialize',
        params: {
          protocolVersion: '2024-11-05',
          capabilities: {},
          clientInfo: { name: 'my-app', version: '1.0.0' }
        },
        id: ++this.requestId
      })
    });

    this.sessionId = response.headers.get('Mcp-Session-Id')
                  || response.headers.get('mcp-session-id');
    return response.json();
  }

  async callTool(name, args = {}, timeout = 60000) {
    if (!this.sessionId) await this.connect();

    const response = await fetch(this.mcpEndpoint, {
      method: 'POST',
      headers: { ...this._getHeaders(), 'Mcp-Session-Id': this.sessionId },
      body: JSON.stringify({
        jsonrpc: '2.0',
        method: 'tools/call',
        params: { name, arguments: args },
        id: ++this.requestId
      }),
      signal: AbortSignal.timeout(timeout)
    });

    const data = await response.json();
    if (data.error) throw new Error(data.error.message);

    const result = data.result || {};
    if (result.structuredContent) return JSON.stringify(result.structuredContent);
    if (result.content?.[0]?.text) return result.content[0].text;
    return JSON.stringify(result);
  }

  _getHeaders() {
    return {
      'Authorization': `Bearer ${this.accessToken}`,
      'Content-Type': 'application/json'
    };
  }
}
```

---

## 全ツールリファレンス (25個)

### ツール名について

- WordPress プラグイン内部では `wp-mcp/tool-name` (スラッシュ区切り)
- MCP `tools/call` で呼び出す際は `wp-mcp-tool-name` (ハイフン区切り)

---

### 分析ツール (Analysis) — 5個

#### wp-mcp/get-posts-by-category

指定カテゴリの記事一覧を取得します。

| パラメータ | 型 | 必須 | デフォルト | 説明 |
|-----------|-----|------|----------|------|
| `category_id` | integer | Yes | — | カテゴリ ID (min: 1) |
| `limit` | integer | No | 10 | 取得件数 (1-100) |
| `order` | string | No | DESC | 並び順: `DESC` / `ASC` |
| `orderby` | string | No | date | ソート基準: `date` / `title` / `modified` |

**レスポンス**: `{ items: [{ post_id, title, date, modified, word_count, status }] }`

#### wp-mcp/get-post-block-structure

記事の Gutenberg ブロック構造を JSON 形式で取得します。

| パラメータ | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `post_id` | integer | Yes | 記事 ID |

**レスポンス**: `{ items: [{ blockName, attrs, innerBlocks, innerHTML }] }`

#### wp-mcp/analyze-category-format-patterns

カテゴリ内の記事から共通フォーマットパターンを抽出します。

| パラメータ | 型 | 必須 | デフォルト | 説明 |
|-----------|-----|------|----------|------|
| `category_id` | integer | Yes | — | カテゴリ ID |
| `sample_count` | integer | No | 5 | サンプル数 (1-20) |

**レスポンス**: `{ category_name, common_blocks, common_classes, typical_structure, heading_patterns }`

#### wp-mcp/get-post-raw-content

記事の生コンテンツ (ブロック HTML) とレンダリング済み HTML を取得します。

| パラメータ | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `post_id` | integer | Yes | 記事 ID |

**レスポンス**: `{ post_id, raw_content, rendered_content }`

#### wp-mcp/extract-used-blocks

記事群から使用ブロックの頻度を集計します。

| パラメータ | 型 | 必須 | デフォルト | 説明 |
|-----------|-----|------|----------|------|
| `post_type` | string | No | post | 投稿タイプ |
| `limit` | integer | No | 100 | スキャン件数 (1-500) |

**レスポンス**: `{ items: [{ block_name, count, percentage }] }`

---

### スタイル・規約ツール (Style) — 4個

#### wp-mcp/get-theme-styles

テーマのグローバルスタイル設定を取得します。

**パラメータ**: なし

**レスポンス**: `{ colors, typography, spacing, layout }`

#### wp-mcp/get-block-patterns

登録済みのブロックパターン一覧を取得します。

| パラメータ | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `category` | string | No | パターンカテゴリで絞り込み |

**レスポンス**: `{ items: [{ name, title, description, content, categories }] }`

#### wp-mcp/get-reusable-blocks

再利用ブロック一覧を取得します。

| パラメータ | 型 | 必須 | デフォルト | 説明 |
|-----------|-----|------|----------|------|
| `per_page` | integer | No | 100 | 取得件数 (1-200) |

**レスポンス**: `{ items: [{ id, title, content, status }] }`

#### wp-mcp/get-article-regulations

カテゴリ別のレギュレーション (執筆規約) 設定を取得します。

| パラメータ | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `category_id` | integer | No | カテゴリ ID で絞り込み |

**レスポンス**: `{ heading_rules, required_sections, allowed_boxes, color_scheme, formatting_rules, configured }`

---

### コンテンツ管理ツール (Content) — 6個

#### wp-mcp/create-draft-post

Gutenberg ブロック HTML で新規下書きを作成します。

| パラメータ | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `title` | string | Yes | 記事タイトル |
| `content` | string | Yes | ブロック HTML コンテンツ |
| `category_ids` | array\<int\> | No | カテゴリ ID 配列 |
| `tag_ids` | array\<int\> | No | タグ ID 配列 |
| `excerpt` | string | No | 抜粋 |
| `meta` | object | No | カスタムメタフィールド (保護キーは不可) |

**レスポンス**: `{ post_id, edit_url, preview_url }`
**権限**: `edit_posts`

#### wp-mcp/update-post-content

既存記事のコンテンツを更新します。

| パラメータ | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `post_id` | integer | Yes | 記事 ID |
| `content` | string | Yes | 新しいブロック HTML |
| `title` | string | No | 新しいタイトル |

**レスポンス**: `{ success, post_id, modified_at }`

#### wp-mcp/update-post-meta

記事のメタ情報を更新します。

| パラメータ | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `post_id` | integer | Yes | 記事 ID |
| `meta_key` | string | Yes | メタキー |
| `meta_value` | mixed | Yes | メタ値 |

**レスポンス**: `{ success, meta_id }`

#### wp-mcp/publish-post

下書き記事を公開または予約投稿します。

| パラメータ | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `post_id` | integer | Yes | 記事 ID |
| `scheduled_time` | string | No | ISO 8601 日時 (予約投稿用) |

**レスポンス**: `{ success, published_url, published_at }`
**権限**: `publish_posts`

#### wp-mcp/delete-post

記事を削除 (ゴミ箱移動または完全削除) します。

| パラメータ | 型 | 必須 | デフォルト | 説明 |
|-----------|-----|------|----------|------|
| `post_id` | integer | Yes | — | 記事 ID |
| `force` | boolean | No | false | true で完全削除、false でゴミ箱 |

**レスポンス**: `{ success, deleted_post_id }`
**権限**: `delete_posts`

---

### バリデーションツール (Validation) — 3個

#### wp-mcp/validate-block-content

ブロックコンテンツの構文・形式チェックを行います。

| パラメータ | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `content` | string | Yes | 検証するブロック HTML |

**レスポンス**: `{ is_valid, block_count, errors, warnings }`

#### wp-mcp/check-regulation-compliance

カテゴリ別レギュレーションへの準拠を検証します。

| パラメータ | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `content` | string | Yes | 検証するコンテンツ |
| `category_id` | integer | Yes | カテゴリ ID |

**レスポンス**: `{ is_compliant, violations, suggestions, score (0-100) }`

#### wp-mcp/check-seo-requirements

SEO 要件チェックを行います。CJK (日本語) テキストは文字ベースのキーワード密度計算に対応。

| パラメータ | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `content` | string | Yes | 検証するコンテンツ |
| `target_keywords` | array\<string\> | No | ターゲットキーワード |
| `title` | string | No | 記事タイトル |

**レスポンス**: `{ score (0-100), keyword_density, heading_structure, issues, recommendations }`

---

### メディアツール (Media) — 3個

#### wp-mcp/get-media-library

メディアライブラリから画像・ファイル一覧を取得します。

| パラメータ | 型 | 必須 | デフォルト | 説明 |
|-----------|-----|------|----------|------|
| `search` | string | No | — | 検索キーワード |
| `mime_type` | string | No | — | MIME タイプで絞り込み |
| `per_page` | integer | No | 20 | 取得件数 (1-100) |

**レスポンス**: `{ items: [{ id, url, title, alt, caption, width, height, mime_type }] }`
**権限**: `upload_files`

#### wp-mcp/upload-media

URL または Base64 からメディアをアップロードします。

| パラメータ | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `source` | string | Yes | 画像 URL or Base64 データ URI |
| `filename` | string | Yes | ファイル名 |
| `title` | string | No | メディアタイトル |
| `alt` | string | No | 代替テキスト |
| `caption` | string | No | キャプション |

**レスポンス**: `{ media_id, url, width, height }`
**権限**: `upload_files`
**タイムアウト**: 300秒推奨 (大きなファイルのアップロード対応)

#### wp-mcp/set-featured-image

記事のアイキャッチ画像を設定します。

| パラメータ | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `post_id` | integer | Yes | 記事 ID |
| `media_id` | integer | Yes | メディア ID |

**レスポンス**: `{ success, thumbnail_url }`

---

### タクソノミーツール (Taxonomy) — 3個

#### wp-mcp/get-categories

カテゴリ一覧を取得します。

| パラメータ | 型 | 必須 | デフォルト | 説明 |
|-----------|-----|------|----------|------|
| `parent` | integer | No | — | 親カテゴリ ID で絞り込み |
| `hide_empty` | boolean | No | false | 空のカテゴリを非表示 |

**レスポンス**: `{ items: [{ id, name, slug, description, parent, count }] }`

#### wp-mcp/get-tags

タグ一覧を取得します (空タグも含む)。

| パラメータ | 型 | 必須 | デフォルト | 説明 |
|-----------|-----|------|----------|------|
| `search` | string | No | — | 検索キーワード |
| `per_page` | integer | No | 100 | 取得件数 (1-200) |

**レスポンス**: `{ items: [{ id, name, slug, count }] }`

#### wp-mcp/create-term

カテゴリまたはタグを新規作成します。

| パラメータ | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `taxonomy` | string | Yes | `category` or `post_tag` |
| `name` | string | Yes | 名前 |
| `slug` | string | No | スラッグ |
| `parent` | integer | No | 親 ID (カテゴリのみ) |

**レスポンス**: `{ term_id, name, slug }`
**権限**: `manage_categories` / `manage_post_tags`

---

### サイト情報ツール (Site) — 2個

#### wp-mcp/get-site-info

サイトの基本情報を取得します。

**パラメータ**: なし

**レスポンス**: `{ name, description, url, language, timezone, gmt_offset }`
※ `admin_email` は `manage_options` 権限を持つユーザーのみ

#### wp-mcp/get-post-types

利用可能な投稿タイプ一覧を取得します。

| パラメータ | 型 | 必須 | デフォルト | 説明 |
|-----------|-----|------|----------|------|
| `public_only` | boolean | No | true | 公開タイプのみ |

**レスポンス**: `{ items: [{ name, label, description, rest_base, hierarchical, has_archive }] }`

---

## 全リソースリファレンス (4個)

リソースは MCP の `resources/read` メソッドで取得します。全て読み取り専用です。

```python
# リソース読み取り例
result = await client.call_tool_raw("resources/read", {"uri": "wordpress://mcp/block_schemas"})
```

| リソース名 | URI | 説明 |
|-----------|-----|------|
| `wp-mcp/block-schemas` | `wordpress://mcp/block_schemas` | 利用可能なブロックタイプのスキーマ定義 (name, title, description, attributes, supports) |
| `wp-mcp/style-guide` | `wordpress://mcp/style_guide` | サイトのスタイルガイドライン (theme_styles + custom_guidelines) |
| `wp-mcp/category-templates` | `wordpress://mcp/category_templates` | カテゴリ別記事ブロックテンプレート |
| `wp-mcp/writing-regulations` | `wordpress://mcp/writing_regulations` | ライティングレギュレーション (カテゴリ別執筆規約) |

### リソースの使い方

リソースは `resources/list` で一覧取得、`resources/read` で内容取得します:

```python
# リソース一覧
response = await send_rpc("resources/list", {})
# → { resources: [{ uri, name, description, mimeType }] }

# リソース読み取り
response = await send_rpc("resources/read", { "uri": "wordpress://mcp/style_guide" })
# → { contents: [{ uri, mimeType, text }] }
```

---

## 全プロンプトリファレンス (4個)

プロンプトは MCP の `prompts/get` メソッドで取得します。LLM に渡す構造化メッセージを返します。

```python
# プロンプト取得例
result = await send_rpc("prompts/get", {
    "name": "wp-mcp/article-generation",
    "arguments": {"category_id": "5", "keywords": '["SEO", "WordPress"]'}
})
# → { messages: [{ role: "system", content: {...} }, { role: "user", content: {...} }] }
```

| プロンプト名 | 説明 |
|-------------|------|
| `wp-mcp/article-generation` | 記事生成用ベースプロンプト |
| `wp-mcp/format-conversion` | プレーンテキスト → Gutenberg ブロック変換プロンプト |
| `wp-mcp/seo-optimization` | SEO 最適化提案生成プロンプト |
| `wp-mcp/regulation-learning` | 既存記事からレギュレーション学習プロンプト |

### wp-mcp/article-generation

| パラメータ | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `category_id` | integer | No | 対象カテゴリ ID |
| `keywords` | array\<string\> | No | ターゲットキーワード |
| `word_count` | integer | No | 目標文字数 |

### wp-mcp/format-conversion

| パラメータ | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `plain_text` | string | Yes | 変換するプレーンテキスト |
| `target_format` | string | No | 目標フォーマット |

### wp-mcp/seo-optimization

| パラメータ | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `post_id` | integer | Yes | 最適化対象の記事 ID |
| `target_keywords` | array\<string\> | No | SEO キーワード |

### wp-mcp/regulation-learning

| パラメータ | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `category_id` | integer | Yes | 分析対象カテゴリ |
| `sample_count` | integer | No | サンプル記事数 |

---

## データベース設計

### 保存すべきデータ

| フィールド | 必須 | 説明 |
|-----------|------|------|
| `site_url` | Yes | WordPress サイトの URL (ユニーク制約推奨) |
| `site_name` | Yes | WordPress サイト名 |
| `mcp_endpoint` | Yes | MCP サーバーの URL |
| `access_token` | Yes | Bearer 認証用トークン (**暗号化必須**) |
| `api_key` | No | API キー (Basic 認証用) |
| `api_secret` | No | API シークレット (Basic 認証用) |
| `connection_id` | No | WordPress 側の連携 UUID |

### テーブル例

```sql
CREATE TABLE wordpress_sites (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id TEXT NOT NULL,
  site_url TEXT UNIQUE NOT NULL,
  site_name TEXT,
  mcp_endpoint TEXT NOT NULL,
  encrypted_credentials TEXT NOT NULL,  -- AES-256-GCM 暗号化
  connection_id TEXT,
  connection_status TEXT DEFAULT 'connected',
  is_active BOOLEAN DEFAULT TRUE,
  last_connected_at TIMESTAMPTZ,
  last_used_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ DEFAULT now(),
  updated_at TIMESTAMPTZ DEFAULT now()
);
```

### 認証情報の暗号化 (AES-256-GCM)

```python
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
import base64, os, json

ENCRYPTION_KEY = base64.b64decode(os.environ['CREDENTIAL_ENCRYPTION_KEY'])  # 32 bytes

def encrypt_credentials(credentials: dict) -> str:
    nonce = os.urandom(12)
    aesgcm = AESGCM(ENCRYPTION_KEY)
    ciphertext = aesgcm.encrypt(nonce, json.dumps(credentials).encode(), None)
    return base64.b64encode(nonce + ciphertext).decode()

def decrypt_credentials(encrypted: str) -> dict:
    data = base64.b64decode(encrypted)
    nonce, ciphertext = data[:12], data[12:]
    aesgcm = AESGCM(ENCRYPTION_KEY)
    return json.loads(aesgcm.decrypt(nonce, ciphertext, None).decode())
```

---

## セキュリティ考慮事項

1. **HTTPS**: WordPress サイト・アプリサーバー共に HTTPS 推奨
2. **暗号化保存**: `access_token`, `api_key`, `api_secret` は AES-256-GCM で暗号化
3. **ログ出力禁止**: 認証情報をログに出力しない (プレフィックス + ハッシュでデバッグ)
4. **登録コード**: 10分有効 / ワンタイム / リトライ不可
5. **トークン管理**: 漏洩時は WordPress 管理画面から連携を解除
6. **公開禁止**: 自動公開はせず、必ず下書きとして作成し管理者が確認後に公開

---

## エラーハンドリング

### 登録時

| コード | HTTP | 対処 |
|--------|------|------|
| `missing_code` | 400 | 接続 URL の解析を確認 |
| `invalid_code` | 401 | 新しい接続 URL を生成するよう案内 |
| `expired_code` | 401 | 新しい接続 URL を生成するよう案内 |

### MCP 通信時

| エラー | 対処 |
|--------|------|
| HTTP 401/403 | トークン無効 (連携解除済み)。再連携を案内 |
| HTTP 404 | プラグインが無効化された可能性 |
| セッションエラー (code -32600) | `initialize` から再接続 |
| タイムアウト | リトライ (メディアアップロードは 300秒推奨) |

---

## トラブルシューティング

### 登録リクエストが 401 を返す
- 登録コードはワンタイム。一度でもリクエストすると無効化
- 有効期限は 10分。WordPress 管理画面から新しい接続 URL を生成

### MCP エンドポイントが 404
- プラグインが有効化されているか確認
- パーマリンク設定が「基本」以外か確認

### 海外サーバーからアクセスできない
- ローカルツールラッパー方式を使用し、アプリサーバー (国内) から WordPress にアクセス
- WAF の海外 IP ブロック設定を確認

### セッション ID が取得できない
- `Mcp-Session-Id` と `mcp-session-id` の両方をチェック

---

## エンドポイント一覧

| メソッド | エンドポイント | 認証 | 用途 |
|---------|--------------|------|------|
| POST | `/wp-json/wp-mcp/v1/register` | registration_code | 連携登録 |
| POST | `/wp-json/mcp/mcp-adapter-default-server` | Bearer / Basic | MCP JSON-RPC サーバー |
| GET | `/wp-json/wp-mcp/v1/connection-status/{id}` | manage_options | 連携ステータス確認 (管理者用) |
| GET | `/.well-known/oauth-protected-resource` | なし | RFC 9728 メタデータ |
| GET | `/.well-known/oauth-authorization-server` | なし | RFC 8414 メタデータ |
| GET | `/.well-known/mcp.json` | なし | MCP メタデータ |
