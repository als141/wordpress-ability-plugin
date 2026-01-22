# SaaS 連携実装ガイド

このドキュメントでは、WordPress MCP Ability Suite プラグインと連携する SaaS サービスの実装方法を詳しく説明します。

## 目次

1. [概要](#概要)
2. [連携フロー](#連携フロー)
3. [エンドポイント実装](#エンドポイント実装)
4. [MCP 通信](#mcp-通信)
5. [データベース設計](#データベース設計)
6. [セキュリティ考慮事項](#セキュリティ考慮事項)
7. [エラーハンドリング](#エラーハンドリング)
8. [実装例](#実装例)

---

## 概要

WordPress MCP Ability Suite は、ワンクリックで SaaS サービスと連携できる仕組みを提供します。

### 連携の特徴

- **ユーザー体験**: ボタンを押すだけで連携完了
- **セキュリティ**: 一時的な登録コードによる安全なクレデンシャル交換
- **永続性**: アクセストークンは期限切れしない
- **フルアクセス**: read / write / admin のすべての権限を取得

### 前提条件

- SaaS サービスは HTTPS でホストされていること
- WordPress サイトも HTTPS を推奨
- SaaS は外部への HTTP リクエストを発行できること

---

## 連携フロー

### シーケンス図

```
┌────────────┐     ┌────────────┐     ┌────────────┐
│   User     │     │ WordPress  │     │   SaaS     │
│  Browser   │     │   Plugin   │     │  Service   │
└─────┬──────┘     └─────┬──────┘     └─────┬──────┘
      │                  │                  │
      │ 1. 「連携する」クリック              │
      ├─────────────────>│                  │
      │                  │                  │
      │ 2. registration_code 生成           │
      │                  │                  │
      │ 3. SaaS にリダイレクト               │
      │<─────────────────┤                  │
      │ Location: /connect/wordpress?...    │
      │                  │                  │
      ├──────────────────────────────────────>
      │                  │                  │
      │                  │ 4. register API 呼出
      │                  │<─────────────────┤
      │                  │ POST /register   │
      │                  │                  │
      │                  │ 5. credentials 返却
      │                  ├─────────────────>│
      │                  │                  │
      │                  │ 6. 認証情報を保存 │
      │                  │                  │
      │ 7. callback にリダイレクト           │
      │<──────────────────────────────────────
      │ Location: /connection-callback?status=success
      │                  │                  │
      ├─────────────────>│                  │
      │ 8. 連携完了表示  │                  │
      │                  │                  │
      │                  │                  │
      │                  │ 9. MCP 通信開始  │
      │                  │<=================>
      │                  │                  │
```

### フロー詳細

| ステップ | アクター | 説明 |
|---------|---------|------|
| 1 | User | WordPress 管理画面で「SaaS と連携する」ボタンをクリック |
| 2 | WordPress | 一時的な `registration_code`（有効期限 10 分）を生成 |
| 3 | WordPress | SaaS の `/connect/wordpress` にリダイレクト |
| 4 | SaaS | WordPress の `/wp-mcp/v1/register` API を呼び出し |
| 5 | WordPress | クレデンシャル（access_token, api_key, api_secret）を返却 |
| 6 | SaaS | 受け取った認証情報をデータベースに保存 |
| 7 | SaaS | WordPress の `callback_url` にリダイレクト |
| 8 | WordPress | 「連携完了」メッセージを表示 |
| 9 | SaaS | 保存した認証情報で MCP 通信を開始 |

---

## エンドポイント実装

### 1. 連携開始エンドポイント

WordPress からのリダイレクトを受け取るエンドポイントを実装します。

**URL**: `GET /connect/wordpress`

**受信パラメータ**:

| パラメータ | 型 | 説明 |
|-----------|-----|------|
| `action` | string | 常に `wordpress_mcp_connect` |
| `site_url` | string | WordPress サイトの URL（URL エンコード済み） |
| `site_name` | string | WordPress サイト名（URL エンコード済み） |
| `mcp_endpoint` | string | MCP サーバーエンドポイント（URL エンコード済み） |
| `register_endpoint` | string | 登録 API エンドポイント（URL エンコード済み） |
| `registration_code` | string | 一時登録コード（64 文字） |
| `callback_url` | string | 完了後のコールバック URL（URL エンコード済み） |

**実装例（Node.js/Express）**:

```javascript
app.get('/connect/wordpress', async (req, res) => {
  const {
    action,
    site_url,
    site_name,
    mcp_endpoint,
    register_endpoint,
    registration_code,
    callback_url
  } = req.query;

  // 1. パラメータ検証
  if (action !== 'wordpress_mcp_connect') {
    return res.status(400).send('Invalid action');
  }

  if (!registration_code || !register_endpoint || !callback_url) {
    return res.status(400).send('Missing required parameters');
  }

  // 2. URL デコード
  const decodedSiteUrl = decodeURIComponent(site_url);
  const decodedSiteName = decodeURIComponent(site_name);
  const decodedMcpEndpoint = decodeURIComponent(mcp_endpoint);
  const decodedRegisterEndpoint = decodeURIComponent(register_endpoint);
  const decodedCallbackUrl = decodeURIComponent(callback_url);

  try {
    // 3. WordPress に登録リクエストを送信
    const credentials = await registerWithWordPress(
      decodedRegisterEndpoint,
      registration_code
    );

    // 4. 認証情報をデータベースに保存
    await saveWordPressSite({
      siteUrl: decodedSiteUrl,
      siteName: decodedSiteName,
      mcpEndpoint: decodedMcpEndpoint,
      accessToken: credentials.access_token,
      apiKey: credentials.api_key,
      apiSecret: credentials.api_secret,
      connectedAt: new Date()
    });

    // 5. 成功コールバックにリダイレクト
    res.redirect(`${decodedCallbackUrl}?status=success`);

  } catch (error) {
    console.error('WordPress connection failed:', error);

    // 6. エラーコールバックにリダイレクト
    const errorMessage = encodeURIComponent(error.message || '連携に失敗しました');
    res.redirect(`${decodedCallbackUrl}?status=error&error=${errorMessage}`);
  }
});
```

### 2. 登録 API 呼び出し

WordPress の `/wp-mcp/v1/register` エンドポイントを呼び出してクレデンシャルを取得します。

**リクエスト**:

```http
POST /wp-json/wp-mcp/v1/register
Content-Type: application/json

{
  "registration_code": "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "saas_identifier": "Your SaaS Name"
}
```

**リクエストパラメータ**:

| パラメータ | 型 | 必須 | 説明 |
|-----------|-----|------|------|
| `registration_code` | string | Yes | WordPress から受け取った一時登録コード |
| `saas_identifier` | string | No | SaaS サービスの識別名（WordPress 管理画面に表示される） |

**成功レスポンス（200 OK）**:

```json
{
  "success": true,
  "mcp_endpoint": "https://example.com/wp-json/mcp/mcp-adapter-default-server",
  "access_token": "a1b2c3d4e5f6g7h8i9j0...",
  "api_key": "mcp_xxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "api_secret": "yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy",
  "site_url": "https://example.com",
  "site_name": "My WordPress Site"
}
```

**レスポンスフィールド**:

| フィールド | 型 | 説明 |
|-----------|-----|------|
| `success` | boolean | 常に `true` |
| `mcp_endpoint` | string | MCP サーバーの URL |
| `access_token` | string | Bearer 認証用アクセストークン（**永久有効**） |
| `api_key` | string | API キー（Basic 認証用） |
| `api_secret` | string | API シークレット（Basic 認証用） |
| `site_url` | string | WordPress サイトの URL |
| `site_name` | string | WordPress サイト名 |

**エラーレスポンス**:

| ステータス | コード | 説明 |
|-----------|--------|------|
| 400 | `missing_code` | registration_code が未指定 |
| 401 | `invalid_code` | 無効な登録コード |
| 401 | `expired_code` | 登録コードの有効期限切れ（10 分） |

```json
{
  "code": "expired_code",
  "message": "登録コードの有効期限が切れています。",
  "data": {
    "status": 401
  }
}
```

**実装例（Node.js）**:

```javascript
async function registerWithWordPress(registerEndpoint, registrationCode) {
  const response = await fetch(registerEndpoint, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      registration_code: registrationCode,
      saas_identifier: 'My SaaS Service'
    })
  });

  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.message || 'Registration failed');
  }

  return response.json();
}
```

---

## MCP 通信

### 認証方法

取得したクレデンシャルを使用して MCP サーバーと通信します。

#### 方法 1: Bearer Token（推奨）

```http
Authorization: Bearer {access_token}
```

```javascript
const response = await fetch(mcpEndpoint, {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${accessToken}`,
    'Content-Type': 'application/json'
  },
  body: JSON.stringify(mcpRequest)
});
```

#### 方法 2: Basic Auth

```http
Authorization: Basic base64({api_key}:{api_secret})
```

```javascript
const credentials = Buffer.from(`${apiKey}:${apiSecret}`).toString('base64');
const response = await fetch(mcpEndpoint, {
  method: 'POST',
  headers: {
    'Authorization': `Basic ${credentials}`,
    'Content-Type': 'application/json'
  },
  body: JSON.stringify(mcpRequest)
});
```

### MCP セッション管理

MCP 通信にはセッション管理が必要です。

#### 1. セッション初期化

```javascript
async function initializeMcpSession(mcpEndpoint, accessToken) {
  const response = await fetch(mcpEndpoint, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${accessToken}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      jsonrpc: '2.0',
      method: 'initialize',
      params: {
        protocolVersion: '2024-11-05',
        capabilities: {},
        clientInfo: {
          name: 'your-saas-name',
          version: '1.0.0'
        }
      },
      id: 1
    })
  });

  // レスポンスヘッダーからセッション ID を取得
  const sessionId = response.headers.get('Mcp-Session-Id');
  const result = await response.json();

  return {
    sessionId,
    serverInfo: result.result
  };
}
```

#### 2. MCP リクエスト送信

```javascript
async function sendMcpRequest(mcpEndpoint, accessToken, sessionId, method, params) {
  const response = await fetch(mcpEndpoint, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${accessToken}`,
      'Content-Type': 'application/json',
      'Mcp-Session-Id': sessionId
    },
    body: JSON.stringify({
      jsonrpc: '2.0',
      method: method,
      params: params,
      id: Date.now()
    })
  });

  return response.json();
}
```

### MCP クライアント実装例

```javascript
class WordPressMcpClient {
  constructor(credentials) {
    this.mcpEndpoint = credentials.mcp_endpoint;
    this.accessToken = credentials.access_token;
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
          clientInfo: {
            name: 'your-saas',
            version: '1.0.0'
          }
        },
        id: ++this.requestId
      })
    });

    this.sessionId = response.headers.get('Mcp-Session-Id');
    return response.json();
  }

  async listTools() {
    return this._request('tools/list', {});
  }

  async callTool(name, args) {
    return this._request('tools/call', {
      name: name,
      arguments: args
    });
  }

  async listResources() {
    return this._request('resources/list', {});
  }

  async readResource(uri) {
    return this._request('resources/read', { uri });
  }

  async listPrompts() {
    return this._request('prompts/list', {});
  }

  async getPrompt(name, args) {
    return this._request('prompts/get', {
      name: name,
      arguments: args
    });
  }

  async _request(method, params) {
    if (!this.sessionId) {
      throw new Error('Not connected. Call connect() first.');
    }

    const response = await fetch(this.mcpEndpoint, {
      method: 'POST',
      headers: {
        ...this._getHeaders(),
        'Mcp-Session-Id': this.sessionId
      },
      body: JSON.stringify({
        jsonrpc: '2.0',
        method: method,
        params: params,
        id: ++this.requestId
      })
    });

    return response.json();
  }

  _getHeaders() {
    return {
      'Authorization': `Bearer ${this.accessToken}`,
      'Content-Type': 'application/json'
    };
  }
}

// 使用例
const client = new WordPressMcpClient(credentials);
await client.connect();

// ツール一覧を取得
const tools = await client.listTools();
console.log(tools.result.tools);

// 記事を作成
const result = await client.callTool('wp-mcp-create-draft-post', {
  title: 'AIが生成した記事',
  content: '<!-- wp:paragraph --><p>記事の本文...</p><!-- /wp:paragraph -->'
});
console.log(result.result);
```

---

## データベース設計

### 必要なテーブル

```sql
CREATE TABLE wordpress_sites (
  id SERIAL PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id),

  -- サイト情報
  site_url VARCHAR(500) NOT NULL,
  site_name VARCHAR(255),
  mcp_endpoint VARCHAR(500) NOT NULL,

  -- 認証情報（暗号化して保存）
  access_token_encrypted TEXT NOT NULL,
  api_key_encrypted TEXT NOT NULL,
  api_secret_encrypted TEXT NOT NULL,

  -- 接続状態
  status VARCHAR(20) DEFAULT 'connected',  -- connected, disconnected, error
  last_connected_at TIMESTAMP,
  last_error TEXT,

  -- メタデータ
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_wordpress_sites_user_id ON wordpress_sites(user_id);
CREATE INDEX idx_wordpress_sites_status ON wordpress_sites(status);
```

### 認証情報の暗号化

認証情報は必ず暗号化して保存してください。

```javascript
const crypto = require('crypto');

const ENCRYPTION_KEY = process.env.ENCRYPTION_KEY; // 32 バイトの秘密鍵
const IV_LENGTH = 16;

function encrypt(text) {
  const iv = crypto.randomBytes(IV_LENGTH);
  const cipher = crypto.createCipheriv('aes-256-cbc', Buffer.from(ENCRYPTION_KEY), iv);
  let encrypted = cipher.update(text);
  encrypted = Buffer.concat([encrypted, cipher.final()]);
  return iv.toString('hex') + ':' + encrypted.toString('hex');
}

function decrypt(text) {
  const parts = text.split(':');
  const iv = Buffer.from(parts[0], 'hex');
  const encryptedText = Buffer.from(parts[1], 'hex');
  const decipher = crypto.createDecipheriv('aes-256-cbc', Buffer.from(ENCRYPTION_KEY), iv);
  let decrypted = decipher.update(encryptedText);
  decrypted = Buffer.concat([decrypted, decipher.final()]);
  return decrypted.toString();
}
```

---

## セキュリティ考慮事項

### 1. HTTPS 必須

- SaaS サービスは必ず HTTPS でホストしてください
- WordPress サイトへのリクエストも HTTPS を使用してください

### 2. 認証情報の保護

- `access_token`、`api_key`、`api_secret` は暗号化して保存
- ログに認証情報を出力しない
- 環境変数で暗号化キーを管理

### 3. 登録コードの検証

- 登録コードは 10 分で期限切れ
- 一度使用された登録コードは再利用不可
- タイムアウト処理を実装

### 4. レート制限

- MCP リクエストにはレート制限を設けることを推奨
- WordPress 側でもレート制限を有効にできます

### 5. エラーメッセージ

- 詳細なエラー情報をユーザーに表示しない
- 内部ログにのみ詳細を記録

---

## エラーハンドリング

### 接続時のエラー

| エラー | 原因 | 対処法 |
|--------|------|--------|
| `missing_code` | registration_code が未指定 | パラメータを確認 |
| `invalid_code` | 登録コードが無効 | ユーザーに再試行を促す |
| `expired_code` | 登録コードが期限切れ | ユーザーに再試行を促す（10 分以内に完了） |
| ネットワークエラー | WordPress への接続失敗 | リトライまたはユーザーに通知 |

### MCP 通信時のエラー

| エラー | 原因 | 対処法 |
|--------|------|--------|
| 401 Unauthorized | トークン無効 | 再接続が必要 |
| 403 Forbidden | 権限不足 | 接続状態を確認 |
| セッションエラー | セッション期限切れ | セッション再初期化 |
| JSON-RPC エラー | リクエスト形式エラー | リクエストを確認 |

### エラー処理の実装例

```javascript
class McpConnectionError extends Error {
  constructor(code, message) {
    super(message);
    this.code = code;
  }
}

async function handleMcpRequest(client, method, params) {
  try {
    const result = await client._request(method, params);

    if (result.error) {
      // JSON-RPC エラー
      throw new McpConnectionError(
        result.error.code,
        result.error.message
      );
    }

    return result.result;

  } catch (error) {
    if (error.code === 401) {
      // 認証エラー - 再接続が必要
      await markSiteDisconnected(client.siteId, error.message);
      throw new McpConnectionError('AUTH_FAILED', '認証に失敗しました。再接続が必要です。');
    }

    if (error.code === -32600) {
      // セッションエラー - 再初期化
      await client.connect();
      return client._request(method, params);
    }

    throw error;
  }
}
```

---

## 実装例

### Node.js (Express) 完全実装

```javascript
// routes/wordpress.js
const express = require('express');
const router = express.Router();
const { WordPressSite } = require('../models');
const { encrypt, decrypt } = require('../utils/crypto');
const WordPressMcpClient = require('../lib/mcp-client');

// 連携開始エンドポイント
router.get('/connect/wordpress', async (req, res) => {
  const {
    action,
    site_url,
    site_name,
    mcp_endpoint,
    register_endpoint,
    registration_code,
    callback_url
  } = req.query;

  // バリデーション
  if (action !== 'wordpress_mcp_connect') {
    return res.status(400).send('Invalid action');
  }

  const requiredParams = ['site_url', 'mcp_endpoint', 'register_endpoint', 'registration_code', 'callback_url'];
  for (const param of requiredParams) {
    if (!req.query[param]) {
      return res.status(400).send(`Missing parameter: ${param}`);
    }
  }

  // デコード
  const decoded = {
    siteUrl: decodeURIComponent(site_url),
    siteName: decodeURIComponent(site_name || ''),
    mcpEndpoint: decodeURIComponent(mcp_endpoint),
    registerEndpoint: decodeURIComponent(register_endpoint),
    callbackUrl: decodeURIComponent(callback_url)
  };

  try {
    // WordPress に登録リクエスト
    const response = await fetch(decoded.registerEndpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        registration_code: registration_code,
        saas_identifier: 'My SaaS Service'
      })
    });

    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'Registration failed');
    }

    const credentials = await response.json();

    // データベースに保存
    // 注: req.user は認証ミドルウェアで設定されていると仮定
    await WordPressSite.create({
      userId: req.user?.id || null,
      siteUrl: decoded.siteUrl,
      siteName: credentials.site_name || decoded.siteName,
      mcpEndpoint: credentials.mcp_endpoint,
      accessTokenEncrypted: encrypt(credentials.access_token),
      apiKeyEncrypted: encrypt(credentials.api_key),
      apiSecretEncrypted: encrypt(credentials.api_secret),
      status: 'connected',
      lastConnectedAt: new Date()
    });

    // 成功コールバック
    res.redirect(`${decoded.callbackUrl}?status=success`);

  } catch (error) {
    console.error('WordPress connection error:', error);
    const errorMessage = encodeURIComponent(
      error.message || '連携に失敗しました。もう一度お試しください。'
    );
    res.redirect(`${decoded.callbackUrl}?status=error&error=${errorMessage}`);
  }
});

// 接続済みサイト一覧
router.get('/sites', async (req, res) => {
  const sites = await WordPressSite.findAll({
    where: { userId: req.user.id },
    attributes: ['id', 'siteUrl', 'siteName', 'status', 'lastConnectedAt']
  });
  res.json(sites);
});

// サイトとの通信テスト
router.post('/sites/:id/test', async (req, res) => {
  const site = await WordPressSite.findByPk(req.params.id);
  if (!site) {
    return res.status(404).json({ error: 'Site not found' });
  }

  try {
    const client = new WordPressMcpClient({
      mcp_endpoint: site.mcpEndpoint,
      access_token: decrypt(site.accessTokenEncrypted)
    });

    await client.connect();
    const siteInfo = await client.callTool('wp-mcp-get-site-info', {});

    res.json({
      success: true,
      siteInfo: siteInfo.structuredContent
    });

  } catch (error) {
    res.json({
      success: false,
      error: error.message
    });
  }
});

// サイト削除（連携解除）
router.delete('/sites/:id', async (req, res) => {
  await WordPressSite.destroy({
    where: {
      id: req.params.id,
      userId: req.user.id
    }
  });
  res.json({ deleted: true });
});

module.exports = router;
```

### Python (FastAPI) 実装例

```python
from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import RedirectResponse
import httpx
from pydantic import BaseModel
from cryptography.fernet import Fernet
import os

app = FastAPI()

# 暗号化キー
ENCRYPTION_KEY = os.environ.get('ENCRYPTION_KEY').encode()
fernet = Fernet(ENCRYPTION_KEY)

class WordPressSite(BaseModel):
    site_url: str
    site_name: str
    mcp_endpoint: str
    access_token: str
    api_key: str
    api_secret: str

# 保存用（実際はDBに保存）
connected_sites = {}

@app.get("/connect/wordpress")
async def connect_wordpress(
    action: str,
    site_url: str,
    site_name: str,
    mcp_endpoint: str,
    register_endpoint: str,
    registration_code: str,
    callback_url: str
):
    if action != "wordpress_mcp_connect":
        raise HTTPException(400, "Invalid action")

    try:
        # WordPress に登録リクエスト
        async with httpx.AsyncClient() as client:
            response = await client.post(
                register_endpoint,
                json={
                    "registration_code": registration_code,
                    "saas_identifier": "My SaaS Service"
                }
            )

            if response.status_code != 200:
                error_data = response.json()
                raise Exception(error_data.get("message", "Registration failed"))

            credentials = response.json()

        # 認証情報を暗号化して保存
        site_id = len(connected_sites) + 1
        connected_sites[site_id] = {
            "site_url": credentials["site_url"],
            "site_name": credentials["site_name"],
            "mcp_endpoint": credentials["mcp_endpoint"],
            "access_token": fernet.encrypt(credentials["access_token"].encode()).decode(),
            "api_key": fernet.encrypt(credentials["api_key"].encode()).decode(),
            "api_secret": fernet.encrypt(credentials["api_secret"].encode()).decode(),
        }

        return RedirectResponse(f"{callback_url}?status=success")

    except Exception as e:
        error_msg = str(e)
        return RedirectResponse(f"{callback_url}?status=error&error={error_msg}")

@app.get("/sites")
async def list_sites():
    return [
        {"id": k, "site_url": v["site_url"], "site_name": v["site_name"]}
        for k, v in connected_sites.items()
    ]

@app.post("/sites/{site_id}/call-tool")
async def call_tool(site_id: int, tool_name: str, arguments: dict):
    if site_id not in connected_sites:
        raise HTTPException(404, "Site not found")

    site = connected_sites[site_id]
    access_token = fernet.decrypt(site["access_token"].encode()).decode()

    # MCP セッション初期化
    async with httpx.AsyncClient() as client:
        # Initialize
        init_response = await client.post(
            site["mcp_endpoint"],
            headers={
                "Authorization": f"Bearer {access_token}",
                "Content-Type": "application/json"
            },
            json={
                "jsonrpc": "2.0",
                "method": "initialize",
                "params": {
                    "protocolVersion": "2024-11-05",
                    "capabilities": {},
                    "clientInfo": {"name": "my-saas", "version": "1.0.0"}
                },
                "id": 1
            }
        )

        session_id = init_response.headers.get("Mcp-Session-Id")

        # Call tool
        tool_response = await client.post(
            site["mcp_endpoint"],
            headers={
                "Authorization": f"Bearer {access_token}",
                "Content-Type": "application/json",
                "Mcp-Session-Id": session_id
            },
            json={
                "jsonrpc": "2.0",
                "method": "tools/call",
                "params": {
                    "name": tool_name,
                    "arguments": arguments
                },
                "id": 2
            }
        )

        return tool_response.json()
```

---

## チェックリスト

SaaS 連携実装が完了したら、以下を確認してください：

### 必須

- [ ] `/connect/wordpress` エンドポイントを実装した
- [ ] `/wp-mcp/v1/register` API を正しく呼び出している
- [ ] クレデンシャルを暗号化して保存している
- [ ] コールバック URL に正しくリダイレクトしている
- [ ] MCP セッション初期化を実装した
- [ ] エラーハンドリングを実装した

### 推奨

- [ ] HTTPS を使用している
- [ ] ログに認証情報を出力していない
- [ ] 接続テスト機能を実装した
- [ ] ユーザーへのエラーメッセージを適切に表示している
- [ ] 接続解除機能を実装した

---

## サポート

実装に関する質問やバグ報告は以下まで：

- GitHub Issues: [リポジトリ URL]
- Email: [サポートメール]
