# WordPress MCP Ability Suite 拡張機能要件ドキュメント

このドキュメントでは、プラグインで追加されるコンテンツやブロックの情報を取得し、AI が執筆に活用できるようにするための拡張機能の実装要件を定義します。

---

## 目次

1. [カスタムタクソノミー](#1-カスタムタクソノミー)
2. [カスタムフィールド（WordPress ネイティブ）](#2-カスタムフィールドwordpress-ネイティブ)
3. [ACF（Advanced Custom Fields）](#3-acfadvanced-custom-fields)
4. [Pods Framework](#4-pods-framework)
5. [Meta Box](#5-meta-box)
6. [ブロック関連の拡張](#6-ブロック関連の拡張)
7. [実装優先度と推奨事項](#7-実装優先度と推奨事項)

---

## 1. カスタムタクソノミー

### 1.1 概要

WordPress では `category` と `post_tag` 以外にも、プラグインやテーマがカスタムタクソノミーを登録できます。現在のプラグインではこれらに対応していません。

### 1.2 実装可能性

| 項目 | 状態 |
|------|------|
| 実装可能性 | **非常に簡単** |
| REST API 必要性 | **不要**（PHP API で完結） |
| 依存関係 | なし（WordPress コア API のみ） |

### 1.3 使用する WordPress API

```php
// 登録済みタクソノミー一覧を取得
$taxonomies = get_taxonomies( $args, $output, $operator );

// 単一のタクソノミー情報を取得
$taxonomy = get_taxonomy( $taxonomy_name );

// タクソノミーのタームを取得
$terms = get_terms( array(
    'taxonomy'   => 'custom_taxonomy',
    'hide_empty' => false,
) );

// 投稿に紐づくタームを取得
$terms = get_the_terms( $post_id, 'custom_taxonomy' );
```

### 1.4 取得可能な情報

| 情報 | 取得方法 | 説明 |
|------|----------|------|
| タクソノミー名 | `$taxonomy->name` | 内部名（スラッグ） |
| ラベル | `$taxonomy->label` | 表示名 |
| 説明 | `$taxonomy->description` | タクソノミーの説明 |
| 階層構造 | `$taxonomy->hierarchical` | カテゴリ型かタグ型か |
| 関連投稿タイプ | `$taxonomy->object_type` | 紐づく投稿タイプ |
| REST API 公開 | `$taxonomy->show_in_rest` | REST API で公開されているか |
| REST ベース | `$taxonomy->rest_base` | REST API のエンドポイント |

### 1.5 実装例

```php
wp_register_ability( 'wp-mcp/get-taxonomies', array(
    'label'       => 'Get Taxonomies',
    'description' => '登録されているカスタムタクソノミー一覧を取得します。',
    'category'    => 'taxonomy',
    'input_schema' => array(
        'type'       => 'object',
        'properties' => array(
            'public_only' => array(
                'type'    => 'boolean',
                'default' => true,
            ),
            'object_type' => array(
                'type'        => 'string',
                'description' => '特定の投稿タイプに紐づくタクソノミーのみ取得',
            ),
        ),
    ),
    'output_schema' => array(
        'type'       => 'object',
        'properties' => array(
            'items' => array(
                'type'  => 'array',
                'items' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'name'         => array( 'type' => 'string' ),
                        'label'        => array( 'type' => 'string' ),
                        'description'  => array( 'type' => 'string' ),
                        'hierarchical' => array( 'type' => 'boolean' ),
                        'object_type'  => array( 'type' => 'array' ),
                        'rest_base'    => array( 'type' => 'string' ),
                    ),
                ),
            ),
        ),
    ),
    'meta' => wp_mcp_meta( array( 'readonly' => true ) ),
    'permission_callback' => static function () {
        return current_user_can( 'read' );
    },
    'execute_callback' => static function ( $input ) {
        $public_only = ! isset( $input['public_only'] ) || ! empty( $input['public_only'] );
        $object_type = isset( $input['object_type'] ) ? sanitize_key( $input['object_type'] ) : '';

        $args = array();
        if ( $public_only ) {
            $args['public'] = true;
        }
        if ( $object_type ) {
            $args['object_type'] = array( $object_type );
        }

        $taxonomies = get_taxonomies( $args, 'objects' );
        $items = array();

        foreach ( $taxonomies as $taxonomy ) {
            $items[] = array(
                'name'         => $taxonomy->name,
                'label'        => $taxonomy->label,
                'description'  => $taxonomy->description,
                'hierarchical' => (bool) $taxonomy->hierarchical,
                'object_type'  => (array) $taxonomy->object_type,
                'rest_base'    => $taxonomy->rest_base ?: $taxonomy->name,
            );
        }

        return array( 'items' => $items );
    },
) );
```

### 1.6 汎用 get-terms ツールの実装例

```php
wp_register_ability( 'wp-mcp/get-terms', array(
    'label'       => 'Get Terms',
    'description' => '任意のタクソノミーのタームを取得します。',
    'category'    => 'taxonomy',
    'input_schema' => array(
        'type'       => 'object',
        'properties' => array(
            'taxonomy' => array(
                'type'        => 'string',
                'description' => 'タクソノミー名（例: category, post_tag, custom_taxonomy）',
            ),
            'hide_empty' => array(
                'type'    => 'boolean',
                'default' => false,
            ),
            'parent' => array(
                'type'        => 'integer',
                'description' => '親タームID（階層タクソノミー用）',
            ),
            'per_page' => array(
                'type'    => 'integer',
                'minimum' => 1,
                'maximum' => 500,
                'default' => 100,
            ),
        ),
        'required' => array( 'taxonomy' ),
    ),
    'output_schema' => array(
        'type'       => 'object',
        'properties' => array(
            'items' => array(
                'type'  => 'array',
                'items' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'term_id'     => array( 'type' => 'integer' ),
                        'name'        => array( 'type' => 'string' ),
                        'slug'        => array( 'type' => 'string' ),
                        'description' => array( 'type' => 'string' ),
                        'parent'      => array( 'type' => 'integer' ),
                        'count'       => array( 'type' => 'integer' ),
                    ),
                ),
            ),
        ),
    ),
    'meta' => wp_mcp_meta( array( 'readonly' => true ) ),
    'permission_callback' => static function () {
        return current_user_can( 'read' );
    },
    'execute_callback' => static function ( $input ) {
        $taxonomy = sanitize_key( $input['taxonomy'] );

        if ( ! taxonomy_exists( $taxonomy ) ) {
            return new WP_Error( 'invalid_taxonomy', 'Taxonomy not found: ' . $taxonomy );
        }

        $args = array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => ! empty( $input['hide_empty'] ),
            'number'     => isset( $input['per_page'] ) ? absint( $input['per_page'] ) : 100,
        );

        if ( isset( $input['parent'] ) ) {
            $args['parent'] = absint( $input['parent'] );
        }

        $terms = get_terms( $args );

        if ( is_wp_error( $terms ) ) {
            return $terms;
        }

        $items = array();
        foreach ( $terms as $term ) {
            $items[] = array(
                'term_id'     => (int) $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'description' => $term->description,
                'parent'      => (int) $term->parent,
                'count'       => (int) $term->count,
            );
        }

        return array( 'items' => $items );
    },
) );
```

### 1.7 要件まとめ

| 要件 | 内容 |
|------|------|
| 追加ツール | `get-taxonomies`, `get-terms` |
| 実装工数 | 小（1-2時間） |
| ユーザー設定 | 不要 |
| 制限事項 | なし |

---

## 2. カスタムフィールド（WordPress ネイティブ）

### 2.1 概要

WordPress コアには `register_meta()` を使用してメタフィールドを登録する仕組みがあります。登録されたメタキーは `get_registered_meta_keys()` で取得可能です。

### 2.2 実装可能性

| 項目 | 状態 |
|------|------|
| 実装可能性 | **簡単** |
| REST API 必要性 | **不要**（PHP API で完結） |
| 依存関係 | なし（WordPress コア API のみ） |

### 2.3 制限事項

**重要**: `register_meta()` で登録されたメタキーのみ取得可能です。

- ACF、Pods、Meta Box などで登録されたフィールドは**含まれない場合があります**
- 直接 `add_post_meta()` / `update_post_meta()` で保存されたメタデータは**スキーマ情報が取得できません**

### 2.4 使用する WordPress API

```php
// 登録済みメタキーを取得
$meta_keys = get_registered_meta_keys( $object_type, $object_subtype );

// 例: post タイプのメタキー
$post_meta_keys = get_registered_meta_keys( 'post', '' );

// 例: 特定のカスタム投稿タイプのメタキー
$article_meta_keys = get_registered_meta_keys( 'post', 'article' );

// 登録されたメタデータの値を取得
$meta_value = get_registered_metadata( $object_type, $object_id, $meta_key );
```

### 2.5 取得可能な情報

```php
$meta_keys = get_registered_meta_keys( 'post', 'my_post_type' );

// 各メタキーの情報
foreach ( $meta_keys as $key => $args ) {
    $args['type'];                // 'string', 'integer', 'number', 'boolean', 'object', 'array'
    $args['description'];         // フィールドの説明
    $args['single'];              // true: 単一値, false: 複数値
    $args['default'];             // デフォルト値
    $args['show_in_rest'];        // REST API で公開されているか
    $args['sanitize_callback'];   // サニタイズ関数
    $args['auth_callback'];       // 認証関数
}
```

### 2.6 実装例

```php
wp_register_ability( 'wp-mcp/get-registered-meta-keys', array(
    'label'       => 'Get Registered Meta Keys',
    'description' => '登録されているカスタムフィールド（メタキー）のスキーマを取得します。',
    'category'    => 'analysis',
    'input_schema' => array(
        'type'       => 'object',
        'properties' => array(
            'object_type' => array(
                'type'    => 'string',
                'enum'    => array( 'post', 'term', 'user', 'comment' ),
                'default' => 'post',
            ),
            'object_subtype' => array(
                'type'        => 'string',
                'description' => '投稿タイプやタクソノミー名（空の場合は全体）',
                'default'     => '',
            ),
        ),
    ),
    'output_schema' => array(
        'type'       => 'object',
        'properties' => array(
            'items' => array(
                'type'  => 'array',
                'items' => array(
                    'type'       => 'object',
                    'properties' => array(
                        'meta_key'     => array( 'type' => 'string' ),
                        'type'         => array( 'type' => 'string' ),
                        'description'  => array( 'type' => 'string' ),
                        'single'       => array( 'type' => 'boolean' ),
                        'default'      => array(),
                        'show_in_rest' => array( 'type' => 'boolean' ),
                    ),
                ),
            ),
        ),
    ),
    'meta' => wp_mcp_meta( array( 'readonly' => true ) ),
    'permission_callback' => static function () {
        return current_user_can( 'read' );
    },
    'execute_callback' => static function ( $input ) {
        $object_type    = isset( $input['object_type'] ) ? $input['object_type'] : 'post';
        $object_subtype = isset( $input['object_subtype'] ) ? $input['object_subtype'] : '';

        $meta_keys = get_registered_meta_keys( $object_type, $object_subtype );
        $items = array();

        foreach ( $meta_keys as $key => $args ) {
            $items[] = array(
                'meta_key'     => $key,
                'type'         => isset( $args['type'] ) ? $args['type'] : 'string',
                'description'  => isset( $args['description'] ) ? $args['description'] : '',
                'single'       => isset( $args['single'] ) ? (bool) $args['single'] : false,
                'default'      => isset( $args['default'] ) ? $args['default'] : null,
                'show_in_rest' => isset( $args['show_in_rest'] ) ? (bool) $args['show_in_rest'] : false,
            );
        }

        return array( 'items' => $items );
    },
) );
```

### 2.7 要件まとめ

| 要件 | 内容 |
|------|------|
| 追加ツール | `get-registered-meta-keys` |
| 実装工数 | 小（1時間） |
| ユーザー設定 | 不要 |
| 制限事項 | `register_meta()` で登録されたフィールドのみ |

---

## 3. ACF（Advanced Custom Fields）

### 3.1 概要

ACF は WordPress で最も人気のあるカスタムフィールドプラグインです。独自の PHP API を持ち、フィールドグループとフィールドの情報を取得できます。

### 3.2 実装可能性

| 項目 | 状態 |
|------|------|
| 実装可能性 | **可能**（条件付き） |
| REST API 必要性 | **フィールド値の取得には不要**、スキーマ取得も PHP API で可能 |
| 依存関係 | ACF プラグインがインストール・有効化されていること |

### 3.3 REST API に関する重要事項

**誤解されやすい点の整理:**

| 操作 | REST API 有効化 | 説明 |
|------|-----------------|------|
| フィールドグループ一覧取得 | **不要** | PHP API で取得可能 |
| フィールド定義取得 | **不要** | PHP API で取得可能 |
| フィールド値の読み取り | **不要** | `get_field()` で取得可能 |
| フィールド値の書き込み | **不要** | `update_field()` で設定可能 |
| WP REST API 経由でのアクセス | **必要** | フィールドグループ設定で有効化が必要 |

**結論**: このプラグインでは PHP API を直接使用するため、**ユーザーが REST API を有効化する必要はありません**。

### 3.4 使用する ACF PHP API

```php
// ACF がインストールされているか確認
if ( ! function_exists( 'acf_get_field_groups' ) ) {
    return new WP_Error( 'acf_not_installed', 'ACF is not installed.' );
}

// すべてのフィールドグループを取得
$field_groups = acf_get_field_groups();

// 特定の条件に合うフィールドグループを取得
$field_groups = acf_get_field_groups( array(
    'post_type' => 'post',
) );

// フィールドグループ内のフィールドを取得
$fields = acf_get_fields( $field_group_key );

// 投稿のフィールド値を取得
$value = get_field( 'field_name', $post_id );

// すべてのフィールド値を取得
$fields = get_fields( $post_id );

// フィールド値を更新
update_field( 'field_name', $value, $post_id );
```

### 3.5 取得可能な情報

#### フィールドグループ情報

```php
$field_groups = acf_get_field_groups();

foreach ( $field_groups as $group ) {
    $group['key'];           // 'group_xxxxx'
    $group['title'];         // 'フィールドグループ名'
    $group['location'];      // 表示条件（投稿タイプ、ページテンプレートなど）
    $group['menu_order'];    // 表示順
    $group['position'];      // 'normal', 'side', 'acf_after_title'
    $group['style'];         // 'default', 'seamless'
    $group['active'];        // 有効/無効
    $group['show_in_rest'];  // REST API 公開設定
}
```

#### フィールド情報

```php
$fields = acf_get_fields( 'group_xxxxx' );

foreach ( $fields as $field ) {
    $field['key'];           // 'field_xxxxx'
    $field['name'];          // フィールド名（メタキー）
    $field['label'];         // 表示ラベル
    $field['type'];          // 'text', 'textarea', 'image', 'repeater', etc.
    $field['instructions'];  // 入力説明
    $field['required'];      // 必須フラグ
    $field['default_value']; // デフォルト値
    $field['placeholder'];   // プレースホルダー
    $field['choices'];       // 選択肢（select, radio, checkbox）
    $field['min'];           // 最小値（number）
    $field['max'];           // 最大値（number）
    $field['sub_fields'];    // サブフィールド（repeater, group）
}
```

### 3.6 ACF フィールドタイプ一覧

| タイプ | 説明 | サブフィールド |
|--------|------|----------------|
| `text` | テキスト | - |
| `textarea` | テキストエリア | - |
| `number` | 数値 | - |
| `range` | 範囲スライダー | - |
| `email` | メールアドレス | - |
| `url` | URL | - |
| `password` | パスワード | - |
| `image` | 画像 | - |
| `file` | ファイル | - |
| `wysiwyg` | WYSIWYG エディタ | - |
| `oembed` | oEmbed | - |
| `gallery` | ギャラリー | - |
| `select` | セレクトボックス | - |
| `checkbox` | チェックボックス | - |
| `radio` | ラジオボタン | - |
| `button_group` | ボタングループ | - |
| `true_false` | 真偽値 | - |
| `link` | リンク | - |
| `post_object` | 投稿オブジェクト | - |
| `page_link` | ページリンク | - |
| `relationship` | リレーションシップ | - |
| `taxonomy` | タクソノミー | - |
| `user` | ユーザー | - |
| `google_map` | Google マップ | - |
| `date_picker` | 日付選択 | - |
| `date_time_picker` | 日時選択 | - |
| `time_picker` | 時間選択 | - |
| `color_picker` | カラーピッカー | - |
| `message` | メッセージ | - |
| `accordion` | アコーディオン | - |
| `tab` | タブ | - |
| `group` | グループ | **あり** |
| `repeater` | リピーター | **あり** |
| `flexible_content` | 柔軟コンテンツ | **あり** |
| `clone` | クローン | - |

### 3.7 実装例

```php
wp_register_ability( 'wp-mcp/get-acf-field-groups', array(
    'label'       => 'Get ACF Field Groups',
    'description' => 'ACF で登録されているフィールドグループとフィールドを取得します。',
    'category'    => 'analysis',
    'input_schema' => array(
        'type'       => 'object',
        'properties' => array(
            'post_type' => array(
                'type'        => 'string',
                'description' => '特定の投稿タイプに紐づくフィールドグループのみ取得',
            ),
            'include_fields' => array(
                'type'    => 'boolean',
                'default' => true,
                'description' => 'フィールド詳細を含めるか',
            ),
        ),
    ),
    'output_schema' => array(
        'type'       => 'object',
        'properties' => array(
            'available' => array( 'type' => 'boolean' ),
            'items'     => array( 'type' => 'array' ),
        ),
    ),
    'meta' => wp_mcp_meta( array( 'readonly' => true ) ),
    'permission_callback' => static function () {
        return current_user_can( 'read' );
    },
    'execute_callback' => static function ( $input ) {
        // ACF がインストールされているか確認
        if ( ! function_exists( 'acf_get_field_groups' ) ) {
            return array(
                'available' => false,
                'items'     => array(),
                'message'   => 'ACF is not installed or activated.',
            );
        }

        $args = array();
        if ( ! empty( $input['post_type'] ) ) {
            $args['post_type'] = sanitize_key( $input['post_type'] );
        }

        $include_fields = ! isset( $input['include_fields'] ) || $input['include_fields'];
        $field_groups = acf_get_field_groups( $args );
        $items = array();

        foreach ( $field_groups as $group ) {
            $item = array(
                'key'          => $group['key'],
                'title'        => $group['title'],
                'location'     => $group['location'],
                'menu_order'   => $group['menu_order'],
                'position'     => $group['position'],
                'active'       => (bool) $group['active'],
                'show_in_rest' => isset( $group['show_in_rest'] ) ? (bool) $group['show_in_rest'] : false,
            );

            if ( $include_fields ) {
                $fields = acf_get_fields( $group['key'] );
                $item['fields'] = array_map( function( $field ) {
                    return wp_mcp_format_acf_field( $field );
                }, $fields ?: array() );
            }

            $items[] = $item;
        }

        return array(
            'available' => true,
            'items'     => $items,
        );
    },
) );

/**
 * ACF フィールドを整形するヘルパー関数
 */
function wp_mcp_format_acf_field( $field ) {
    $formatted = array(
        'key'           => $field['key'],
        'name'          => $field['name'],
        'label'         => $field['label'],
        'type'          => $field['type'],
        'instructions'  => isset( $field['instructions'] ) ? $field['instructions'] : '',
        'required'      => isset( $field['required'] ) ? (bool) $field['required'] : false,
        'default_value' => isset( $field['default_value'] ) ? $field['default_value'] : null,
    );

    // タイプ固有の情報を追加
    switch ( $field['type'] ) {
        case 'select':
        case 'checkbox':
        case 'radio':
        case 'button_group':
            $formatted['choices'] = isset( $field['choices'] ) ? $field['choices'] : array();
            $formatted['multiple'] = isset( $field['multiple'] ) ? (bool) $field['multiple'] : false;
            break;

        case 'number':
        case 'range':
            $formatted['min'] = isset( $field['min'] ) ? $field['min'] : null;
            $formatted['max'] = isset( $field['max'] ) ? $field['max'] : null;
            $formatted['step'] = isset( $field['step'] ) ? $field['step'] : null;
            break;

        case 'text':
        case 'textarea':
            $formatted['maxlength'] = isset( $field['maxlength'] ) ? $field['maxlength'] : null;
            $formatted['placeholder'] = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
            break;

        case 'image':
        case 'file':
        case 'gallery':
            $formatted['return_format'] = isset( $field['return_format'] ) ? $field['return_format'] : 'array';
            $formatted['mime_types'] = isset( $field['mime_types'] ) ? $field['mime_types'] : '';
            break;

        case 'post_object':
        case 'relationship':
            $formatted['post_type'] = isset( $field['post_type'] ) ? $field['post_type'] : array();
            $formatted['taxonomy'] = isset( $field['taxonomy'] ) ? $field['taxonomy'] : array();
            break;

        case 'taxonomy':
            $formatted['taxonomy'] = isset( $field['taxonomy'] ) ? $field['taxonomy'] : 'category';
            $formatted['field_type'] = isset( $field['field_type'] ) ? $field['field_type'] : 'checkbox';
            break;

        case 'repeater':
        case 'group':
            if ( ! empty( $field['sub_fields'] ) ) {
                $formatted['sub_fields'] = array_map( 'wp_mcp_format_acf_field', $field['sub_fields'] );
            }
            break;

        case 'flexible_content':
            if ( ! empty( $field['layouts'] ) ) {
                $formatted['layouts'] = array_map( function( $layout ) {
                    return array(
                        'key'        => $layout['key'],
                        'name'       => $layout['name'],
                        'label'      => $layout['label'],
                        'sub_fields' => array_map( 'wp_mcp_format_acf_field', $layout['sub_fields'] ?? array() ),
                    );
                }, $field['layouts'] );
            }
            break;
    }

    return $formatted;
}
```

### 3.8 ACF フィールド値の取得ツール

```php
wp_register_ability( 'wp-mcp/get-acf-fields', array(
    'label'       => 'Get ACF Field Values',
    'description' => '投稿の ACF フィールド値を取得します。',
    'category'    => 'analysis',
    'input_schema' => array(
        'type'       => 'object',
        'properties' => array(
            'post_id' => array(
                'type'    => 'integer',
                'minimum' => 1,
            ),
            'field_name' => array(
                'type'        => 'string',
                'description' => '特定のフィールド名（省略時は全フィールド）',
            ),
        ),
        'required' => array( 'post_id' ),
    ),
    'output_schema' => array(
        'type'       => 'object',
        'properties' => array(
            'available' => array( 'type' => 'boolean' ),
            'fields'    => array( 'type' => 'object' ),
        ),
    ),
    'meta' => wp_mcp_meta( array( 'readonly' => true ) ),
    'permission_callback' => static function ( $input ) {
        $post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
        return $post_id ? current_user_can( 'read_post', $post_id ) : current_user_can( 'read' );
    },
    'execute_callback' => static function ( $input ) {
        if ( ! function_exists( 'get_fields' ) ) {
            return array(
                'available' => false,
                'fields'    => array(),
                'message'   => 'ACF is not installed or activated.',
            );
        }

        $post_id = absint( $input['post_id'] );

        if ( ! empty( $input['field_name'] ) ) {
            $value = get_field( $input['field_name'], $post_id );
            return array(
                'available' => true,
                'fields'    => array( $input['field_name'] => $value ),
            );
        }

        $fields = get_fields( $post_id );

        return array(
            'available' => true,
            'fields'    => $fields ?: array(),
        );
    },
) );
```

### 3.9 要件まとめ

| 要件 | 内容 |
|------|------|
| 追加ツール | `get-acf-field-groups`, `get-acf-fields` |
| 実装工数 | 中（2-3時間） |
| ユーザー設定 | **不要**（REST API 有効化は不要） |
| 依存関係 | ACF プラグイン（Free または Pro） |
| 制限事項 | ACF 未インストール時はエラーを返す |

---

## 4. Pods Framework

### 4.1 概要

Pods は WordPress のカスタムコンテンツタイプ、フィールド、タクソノミーを管理する人気のフレームワークです。

### 4.2 実装可能性

| 項目 | 状態 |
|------|------|
| 実装可能性 | **可能** |
| REST API 必要性 | **不要**（PHP API で完結） |
| 依存関係 | Pods プラグインがインストール・有効化されていること |

### 4.3 使用する Pods PHP API

```php
// Pods がインストールされているか確認
if ( ! function_exists( 'pods_api' ) ) {
    return new WP_Error( 'pods_not_installed', 'Pods is not installed.' );
}

// Pods API インスタンスを取得
$api = pods_api();

// すべての Pod を取得
$all_pods = $api->load_pods();

// 特定の Pod を取得
$pod = $api->load_pod( array( 'name' => 'my_pod' ) );

// Pod のフィールドを取得
$fields = $pod['fields'];

// Pod データを取得
$pods = pods( 'my_pod', $id );
$value = $pods->field( 'field_name' );
```

### 4.4 取得可能な情報

```php
$api = pods_api();
$all_pods = $api->load_pods();

foreach ( $all_pods as $pod ) {
    $pod['name'];        // Pod 名
    $pod['label'];       // 表示ラベル
    $pod['type'];        // 'post_type', 'taxonomy', 'pod', 'user', 'comment', 'media', 'settings'
    $pod['storage'];     // 'meta', 'table', 'none'
    $pod['fields'];      // フィールド配列

    foreach ( $pod['fields'] as $field ) {
        $field['name'];           // フィールド名
        $field['label'];          // 表示ラベル
        $field['type'];           // フィールドタイプ
        $field['description'];    // 説明
        $field['required'];       // 必須フラグ
        $field['options'];        // オプション（選択肢など）
    }
}
```

### 4.5 実装例

```php
wp_register_ability( 'wp-mcp/get-pods-schema', array(
    'label'       => 'Get Pods Schema',
    'description' => 'Pods で登録されている Pod とフィールドを取得します。',
    'category'    => 'analysis',
    'input_schema' => array(
        'type'       => 'object',
        'properties' => array(
            'pod_name' => array(
                'type'        => 'string',
                'description' => '特定の Pod 名（省略時は全 Pod）',
            ),
        ),
    ),
    'output_schema' => array(
        'type'       => 'object',
        'properties' => array(
            'available' => array( 'type' => 'boolean' ),
            'items'     => array( 'type' => 'array' ),
        ),
    ),
    'meta' => wp_mcp_meta( array( 'readonly' => true ) ),
    'permission_callback' => static function () {
        return current_user_can( 'read' );
    },
    'execute_callback' => static function ( $input ) {
        if ( ! function_exists( 'pods_api' ) ) {
            return array(
                'available' => false,
                'items'     => array(),
                'message'   => 'Pods is not installed or activated.',
            );
        }

        $api = pods_api();

        if ( ! empty( $input['pod_name'] ) ) {
            $pod = $api->load_pod( array( 'name' => sanitize_key( $input['pod_name'] ) ) );
            if ( ! $pod ) {
                return new WP_Error( 'pod_not_found', 'Pod not found.' );
            }
            $pods = array( $pod );
        } else {
            $pods = $api->load_pods();
        }

        $items = array();
        foreach ( $pods as $pod ) {
            $fields = array();
            if ( ! empty( $pod['fields'] ) ) {
                foreach ( $pod['fields'] as $field ) {
                    $fields[] = array(
                        'name'        => $field['name'],
                        'label'       => isset( $field['label'] ) ? $field['label'] : $field['name'],
                        'type'        => $field['type'],
                        'description' => isset( $field['description'] ) ? $field['description'] : '',
                        'required'    => isset( $field['required'] ) ? (bool) $field['required'] : false,
                    );
                }
            }

            $items[] = array(
                'name'    => $pod['name'],
                'label'   => isset( $pod['label'] ) ? $pod['label'] : $pod['name'],
                'type'    => $pod['type'],
                'storage' => isset( $pod['storage'] ) ? $pod['storage'] : 'meta',
                'fields'  => $fields,
            );
        }

        return array(
            'available' => true,
            'items'     => $items,
        );
    },
) );
```

### 4.6 要件まとめ

| 要件 | 内容 |
|------|------|
| 追加ツール | `get-pods-schema` |
| 実装工数 | 中（2時間） |
| ユーザー設定 | 不要 |
| 依存関係 | Pods プラグイン |
| 制限事項 | Pods 未インストール時はエラーを返す |

---

## 5. Meta Box

### 5.1 概要

Meta Box は高性能なカスタムフィールドプラグインで、独自のレジストリシステムを持っています。

### 5.2 実装可能性

| 項目 | 状態 |
|------|------|
| 実装可能性 | **可能** |
| REST API 必要性 | **不要**（PHP API で完結） |
| 依存関係 | Meta Box プラグインがインストール・有効化されていること |

### 5.3 重要な注意点

Meta Box のレジストリは `init` フックの優先度 20 で初期化されます。したがって、フィールド情報を取得するには `init` 後（優先度 20 以降）に実行する必要があります。

### 5.4 使用する Meta Box PHP API

```php
// Meta Box がインストールされているか確認
if ( ! function_exists( 'rwmb_get_registry' ) ) {
    return new WP_Error( 'metabox_not_installed', 'Meta Box is not installed.' );
}

// メタボックスレジストリを取得
$meta_box_registry = rwmb_get_registry( 'meta_box' );

// すべてのメタボックスを取得
$meta_boxes = $meta_box_registry->all();

// 条件でフィルタリング
$meta_boxes = $meta_box_registry->get_by( array(
    'object_type' => 'post',
    'post_types'  => array( 'post' ),
) );

// フィールドレジストリを取得
$field_registry = rwmb_get_registry( 'field' );

// 特定オブジェクトのフィールドを取得
$fields = rwmb_get_object_fields( 'post', 'post' ); // (post_type, object_type)

// フィールド値を取得
$value = rwmb_meta( 'field_id', array(), $post_id );
```

### 5.5 取得可能な情報

```php
$meta_box_registry = rwmb_get_registry( 'meta_box' );
$meta_boxes = $meta_box_registry->all();

foreach ( $meta_boxes as $meta_box ) {
    $meta_box->id;           // メタボックス ID
    $meta_box->title;        // タイトル
    $meta_box->post_types;   // 対象投稿タイプ
    $meta_box->context;      // 'normal', 'side', 'advanced'
    $meta_box->priority;     // 'high', 'low'
    $meta_box->fields;       // フィールド配列

    foreach ( $meta_box->fields as $field ) {
        $field['id'];           // フィールド ID
        $field['name'];         // 表示名
        $field['type'];         // フィールドタイプ
        $field['desc'];         // 説明
        $field['std'];          // デフォルト値
        $field['required'];     // 必須フラグ
        $field['options'];      // 選択肢
        $field['clone'];        // クローン可能か
    }
}
```

### 5.6 実装例

```php
wp_register_ability( 'wp-mcp/get-metabox-schema', array(
    'label'       => 'Get Meta Box Schema',
    'description' => 'Meta Box で登録されているメタボックスとフィールドを取得します。',
    'category'    => 'analysis',
    'input_schema' => array(
        'type'       => 'object',
        'properties' => array(
            'post_type' => array(
                'type'        => 'string',
                'description' => '特定の投稿タイプのメタボックスのみ取得',
            ),
        ),
    ),
    'output_schema' => array(
        'type'       => 'object',
        'properties' => array(
            'available' => array( 'type' => 'boolean' ),
            'items'     => array( 'type' => 'array' ),
        ),
    ),
    'meta' => wp_mcp_meta( array( 'readonly' => true ) ),
    'permission_callback' => static function () {
        return current_user_can( 'read' );
    },
    'execute_callback' => static function ( $input ) {
        if ( ! function_exists( 'rwmb_get_registry' ) ) {
            return array(
                'available' => false,
                'items'     => array(),
                'message'   => 'Meta Box is not installed or activated.',
            );
        }

        $meta_box_registry = rwmb_get_registry( 'meta_box' );

        if ( ! empty( $input['post_type'] ) ) {
            $meta_boxes = $meta_box_registry->get_by( array(
                'object_type' => 'post',
                'post_types'  => array( sanitize_key( $input['post_type'] ) ),
            ) );
        } else {
            $meta_boxes = $meta_box_registry->all();
        }

        $items = array();
        foreach ( $meta_boxes as $meta_box ) {
            $fields = array();
            if ( ! empty( $meta_box->fields ) ) {
                foreach ( $meta_box->fields as $field ) {
                    $fields[] = array(
                        'id'       => $field['id'],
                        'name'     => isset( $field['name'] ) ? $field['name'] : $field['id'],
                        'type'     => $field['type'],
                        'desc'     => isset( $field['desc'] ) ? $field['desc'] : '',
                        'required' => isset( $field['required'] ) ? (bool) $field['required'] : false,
                        'std'      => isset( $field['std'] ) ? $field['std'] : null,
                    );
                }
            }

            $items[] = array(
                'id'         => $meta_box->id,
                'title'      => $meta_box->title,
                'post_types' => (array) $meta_box->post_types,
                'context'    => $meta_box->context,
                'priority'   => $meta_box->priority,
                'fields'     => $fields,
            );
        }

        return array(
            'available' => true,
            'items'     => $items,
        );
    },
) );
```

### 5.7 要件まとめ

| 要件 | 内容 |
|------|------|
| 追加ツール | `get-metabox-schema` |
| 実装工数 | 中（2時間） |
| ユーザー設定 | 不要 |
| 依存関係 | Meta Box プラグイン |
| 制限事項 | `init` フック優先度 20 以降で実行する必要あり |

---

## 6. ブロック関連の拡張

### 6.1 現状の実装

現在の `block-schemas` リソースでは以下の情報を取得しています：

```php
$schemas[] = array(
    'name'        => $block->name,
    'title'       => $block->title,
    'description' => $block->description,
    'attributes'  => $block->attributes,
    'supports'    => $block->supports,
);
```

### 6.2 拡張可能な追加情報

| 情報 | プロパティ | 説明 |
|------|-----------|------|
| カテゴリ | `$block->category` | ブロックのカテゴリ |
| アイコン | `$block->icon` | ブロックアイコン |
| キーワード | `$block->keywords` | 検索キーワード |
| スタイル | `$block->styles` | ブロックスタイルバリエーション |
| バリエーション | `$block->variations` | ブロックバリエーション |
| 例 | `$block->example` | プレビュー用のサンプルデータ |
| 親ブロック | `$block->parent` | 許可される親ブロック |
| 祖先ブロック | `$block->ancestor` | 許可される祖先ブロック |
| エディタスクリプト | `$block->editor_script` | エディタ用スクリプト |
| エディタスタイル | `$block->editor_style` | エディタ用スタイル |
| フロントスクリプト | `$block->script` | フロント用スクリプト |
| フロントスタイル | `$block->style` | フロント用スタイル |

### 6.3 拡張実装例

```php
wp_register_ability( 'wp-mcp/get-block-details', array(
    'label'       => 'Get Block Details',
    'description' => '登録済みブロックの詳細情報を取得します（属性、スタイル、バリエーション等）。',
    'category'    => 'analysis',
    'input_schema' => array(
        'type'       => 'object',
        'properties' => array(
            'block_name' => array(
                'type'        => 'string',
                'description' => '特定のブロック名（例: core/paragraph）',
            ),
            'category' => array(
                'type'        => 'string',
                'description' => 'ブロックカテゴリでフィルタ',
            ),
            'include_example' => array(
                'type'    => 'boolean',
                'default' => false,
                'description' => 'サンプルデータを含めるか',
            ),
        ),
    ),
    'output_schema' => array(
        'type'       => 'object',
        'properties' => array(
            'items' => array( 'type' => 'array' ),
        ),
    ),
    'meta' => wp_mcp_meta( array( 'readonly' => true ) ),
    'permission_callback' => static function () {
        return current_user_can( 'read' );
    },
    'execute_callback' => static function ( $input ) {
        $registry = WP_Block_Type_Registry::get_instance();

        if ( ! empty( $input['block_name'] ) ) {
            $block = $registry->get_registered( $input['block_name'] );
            if ( ! $block ) {
                return new WP_Error( 'block_not_found', 'Block not found: ' . $input['block_name'] );
            }
            $blocks = array( $block );
        } else {
            $blocks = $registry->get_all_registered();
        }

        $category_filter = ! empty( $input['category'] ) ? $input['category'] : null;
        $include_example = ! empty( $input['include_example'] );

        $items = array();
        foreach ( $blocks as $block ) {
            // カテゴリフィルタ
            if ( $category_filter && $block->category !== $category_filter ) {
                continue;
            }

            $item = array(
                'name'        => $block->name,
                'title'       => $block->title,
                'description' => $block->description,
                'category'    => $block->category,
                'icon'        => is_string( $block->icon ) ? $block->icon : null,
                'keywords'    => $block->keywords ?: array(),
                'attributes'  => $block->attributes ?: array(),
                'supports'    => $block->supports ?: array(),
                'styles'      => $block->styles ?: array(),
                'parent'      => $block->parent ?: array(),
                'ancestor'    => $block->ancestor ?: array(),
            );

            // バリエーション
            if ( ! empty( $block->variations ) ) {
                $item['variations'] = array_map( function( $var ) {
                    return array(
                        'name'        => $var['name'] ?? '',
                        'title'       => $var['title'] ?? '',
                        'description' => $var['description'] ?? '',
                        'icon'        => isset( $var['icon'] ) && is_string( $var['icon'] ) ? $var['icon'] : null,
                        'attributes'  => $var['attributes'] ?? array(),
                        'isDefault'   => $var['isDefault'] ?? false,
                    );
                }, $block->variations );
            }

            // サンプルデータ
            if ( $include_example && ! empty( $block->example ) ) {
                $item['example'] = $block->example;
            }

            $items[] = $item;
        }

        return array( 'items' => $items );
    },
) );
```

### 6.4 ブロック使用パターン分析ツール

```php
wp_register_ability( 'wp-mcp/analyze-block-usage-patterns', array(
    'label'       => 'Analyze Block Usage Patterns',
    'description' => '既存記事から特定ブロックの使用パターンを分析します。',
    'category'    => 'analysis',
    'input_schema' => array(
        'type'       => 'object',
        'properties' => array(
            'block_name' => array(
                'type'        => 'string',
                'description' => '分析対象のブロック名',
            ),
            'post_type' => array(
                'type'    => 'string',
                'default' => 'post',
            ),
            'sample_count' => array(
                'type'    => 'integer',
                'minimum' => 1,
                'maximum' => 100,
                'default' => 20,
            ),
        ),
        'required' => array( 'block_name' ),
    ),
    'output_schema' => array(
        'type'       => 'object',
        'properties' => array(
            'block_name'       => array( 'type' => 'string' ),
            'total_occurrences'=> array( 'type' => 'integer' ),
            'posts_with_block' => array( 'type' => 'integer' ),
            'common_attributes'=> array( 'type' => 'object' ),
            'common_classes'   => array( 'type' => 'array' ),
            'context_patterns' => array( 'type' => 'array' ),
            'sample_html'      => array( 'type' => 'array' ),
        ),
    ),
    'meta' => wp_mcp_meta( array( 'readonly' => true ) ),
    'permission_callback' => static function () {
        return current_user_can( 'read' );
    },
    'execute_callback' => static function ( $input ) {
        $block_name   = $input['block_name'];
        $post_type    = isset( $input['post_type'] ) ? $input['post_type'] : 'post';
        $sample_count = isset( $input['sample_count'] ) ? (int) $input['sample_count'] : 20;

        $posts = get_posts( array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'numberposts'    => $sample_count,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        $total_occurrences = 0;
        $posts_with_block  = 0;
        $attribute_counts  = array();
        $class_counts      = array();
        $context_before    = array();
        $context_after     = array();
        $sample_html       = array();

        foreach ( $posts as $post ) {
            $blocks = parse_blocks( $post->post_content );
            $found_in_post = false;
            $flat_blocks = array();
            wp_mcp_flatten_blocks( $blocks, $flat_blocks );

            foreach ( $flat_blocks as $index => $block ) {
                if ( $block['blockName'] !== $block_name ) {
                    continue;
                }

                $found_in_post = true;
                $total_occurrences++;

                // 属性の集計
                if ( ! empty( $block['attrs'] ) ) {
                    foreach ( $block['attrs'] as $attr_key => $attr_value ) {
                        $key = $attr_key . ':' . ( is_scalar( $attr_value ) ? $attr_value : wp_json_encode( $attr_value ) );
                        $attribute_counts[ $key ] = ( $attribute_counts[ $key ] ?? 0 ) + 1;
                    }
                }

                // クラスの集計
                if ( ! empty( $block['attrs']['className'] ) ) {
                    $classes = explode( ' ', $block['attrs']['className'] );
                    foreach ( $classes as $class ) {
                        $class = trim( $class );
                        if ( $class ) {
                            $class_counts[ $class ] = ( $class_counts[ $class ] ?? 0 ) + 1;
                        }
                    }
                }

                // 前後のブロックコンテキスト
                if ( $index > 0 && isset( $flat_blocks[ $index - 1 ]['blockName'] ) ) {
                    $before = $flat_blocks[ $index - 1 ]['blockName'];
                    $context_before[ $before ] = ( $context_before[ $before ] ?? 0 ) + 1;
                }
                if ( isset( $flat_blocks[ $index + 1 ]['blockName'] ) ) {
                    $after = $flat_blocks[ $index + 1 ]['blockName'];
                    $context_after[ $after ] = ( $context_after[ $after ] ?? 0 ) + 1;
                }

                // サンプル HTML（最大5件）
                if ( count( $sample_html ) < 5 && ! empty( $block['innerHTML'] ) ) {
                    $sample_html[] = trim( $block['innerHTML'] );
                }
            }

            if ( $found_in_post ) {
                $posts_with_block++;
            }
        }

        // 結果を整形
        arsort( $attribute_counts );
        arsort( $class_counts );
        arsort( $context_before );
        arsort( $context_after );

        return array(
            'block_name'        => $block_name,
            'total_occurrences' => $total_occurrences,
            'posts_with_block'  => $posts_with_block,
            'common_attributes' => array_slice( $attribute_counts, 0, 10, true ),
            'common_classes'    => array_keys( array_slice( $class_counts, 0, 10, true ) ),
            'context_patterns'  => array(
                'commonly_before' => array_keys( array_slice( $context_before, 0, 5, true ) ),
                'commonly_after'  => array_keys( array_slice( $context_after, 0, 5, true ) ),
            ),
            'sample_html'       => $sample_html,
        );
    },
) );

/**
 * ブロックをフラット化するヘルパー関数
 */
function wp_mcp_flatten_blocks( $blocks, &$flat ) {
    foreach ( $blocks as $block ) {
        $flat[] = $block;
        if ( ! empty( $block['innerBlocks'] ) ) {
            wp_mcp_flatten_blocks( $block['innerBlocks'], $flat );
        }
    }
}
```

### 6.5 ブロックカテゴリ一覧取得

```php
wp_register_ability( 'wp-mcp/get-block-categories', array(
    'label'       => 'Get Block Categories',
    'description' => '登録されているブロックカテゴリ一覧を取得します。',
    'category'    => 'analysis',
    'input_schema' => array(
        'type' => 'object',
    ),
    'output_schema' => array(
        'type'       => 'object',
        'properties' => array(
            'items' => array( 'type' => 'array' ),
        ),
    ),
    'meta' => wp_mcp_meta( array( 'readonly' => true ) ),
    'permission_callback' => static function () {
        return current_user_can( 'read' );
    },
    'execute_callback' => static function () {
        // WordPress 5.8 以降
        if ( function_exists( 'get_block_categories' ) ) {
            // ダミーの投稿コンテキストを作成
            $post = get_post( get_option( 'page_on_front' ) ) ?: (object) array( 'post_type' => 'post' );
            $categories = get_block_categories( $post );
        } else {
            // フォールバック: レジストリから収集
            $registry = WP_Block_Type_Registry::get_instance();
            $category_slugs = array();
            foreach ( $registry->get_all_registered() as $block ) {
                if ( $block->category ) {
                    $category_slugs[ $block->category ] = true;
                }
            }
            $categories = array_map( function( $slug ) {
                return array( 'slug' => $slug, 'title' => ucfirst( str_replace( '-', ' ', $slug ) ) );
            }, array_keys( $category_slugs ) );
        }

        $items = array();
        foreach ( $categories as $category ) {
            $items[] = array(
                'slug'  => $category['slug'],
                'title' => $category['title'],
                'icon'  => isset( $category['icon'] ) ? $category['icon'] : null,
            );
        }

        return array( 'items' => $items );
    },
) );
```

### 6.6 要件まとめ

| 要件 | 内容 |
|------|------|
| 追加ツール | `get-block-details`, `analyze-block-usage-patterns`, `get-block-categories` |
| 実装工数 | 中（3-4時間） |
| ユーザー設定 | 不要 |
| 依存関係 | なし（WordPress コア API のみ） |
| 制限事項 | なし |

---

## 7. 実装優先度と推奨事項

### 7.1 実装優先度

| 優先度 | 機能 | 理由 |
|--------|------|------|
| **高** | カスタムタクソノミー | 実装が簡単で、多くのサイトで使用されている |
| **高** | ACF フィールドグループ | 最も人気のあるカスタムフィールドプラグイン |
| **高** | ブロック詳細情報 | 既存の block-schemas の強化で対応可能 |
| **中** | ブロック使用パターン分析 | AI の執筆精度向上に貢献 |
| **中** | WordPress ネイティブメタキー | 一部のプラグインで使用 |
| **低** | Pods スキーマ | ユーザー数は ACF より少ない |
| **低** | Meta Box スキーマ | ユーザー数は ACF より少ない |

### 7.2 実装フェーズ提案

#### フェーズ 1（必須）
1. `get-taxonomies` - カスタムタクソノミー一覧
2. `get-terms` - 汎用ターム取得
3. `get-acf-field-groups` - ACF フィールドグループ

#### フェーズ 2（推奨）
4. `get-acf-fields` - ACF フィールド値取得
5. `get-block-details` - ブロック詳細情報
6. `get-block-categories` - ブロックカテゴリ一覧

#### フェーズ 3（オプション）
7. `analyze-block-usage-patterns` - ブロック使用パターン分析
8. `get-registered-meta-keys` - ネイティブメタキー
9. `get-pods-schema` - Pods スキーマ
10. `get-metabox-schema` - Meta Box スキーマ

### 7.3 統合ツールの提案

複数のカスタムフィールドプラグインに対応する統合ツールを作成することも可能です：

```php
wp_register_ability( 'wp-mcp/get-custom-fields-schema', array(
    'label'       => 'Get Custom Fields Schema',
    'description' => '利用可能なカスタムフィールドプラグインからスキーマを取得します。',
    'execute_callback' => static function ( $input ) {
        $schemas = array(
            'native'   => array( 'available' => true, 'items' => array() ),
            'acf'      => array( 'available' => false, 'items' => array() ),
            'pods'     => array( 'available' => false, 'items' => array() ),
            'metabox'  => array( 'available' => false, 'items' => array() ),
        );

        // WordPress ネイティブ
        $schemas['native']['items'] = get_registered_meta_keys( 'post', '' );

        // ACF
        if ( function_exists( 'acf_get_field_groups' ) ) {
            $schemas['acf']['available'] = true;
            $schemas['acf']['items'] = acf_get_field_groups();
        }

        // Pods
        if ( function_exists( 'pods_api' ) ) {
            $schemas['pods']['available'] = true;
            $schemas['pods']['items'] = pods_api()->load_pods();
        }

        // Meta Box
        if ( function_exists( 'rwmb_get_registry' ) ) {
            $schemas['metabox']['available'] = true;
            $schemas['metabox']['items'] = rwmb_get_registry( 'meta_box' )->all();
        }

        return $schemas;
    },
) );
```

### 7.4 重要な注意事項

1. **フォールバック処理**: 各プラグインが未インストールの場合、エラーではなく `available: false` を返すようにする

2. **パフォーマンス**: 大量のフィールドがある場合、ページネーションやキャッシュを検討する

3. **セキュリティ**: フィールドの機密性を考慮し、適切な権限チェックを行う

4. **互換性**: WordPress および各プラグインのバージョン互換性を確認する

---

## 参考リンク

- [WordPress Developer Resources - get_taxonomies()](https://developer.wordpress.org/reference/functions/get_taxonomies/)
- [WordPress Developer Resources - get_terms()](https://developer.wordpress.org/reference/functions/get_terms/)
- [WordPress Developer Resources - register_meta()](https://developer.wordpress.org/reference/functions/register_meta/)
- [WordPress Developer Resources - get_registered_meta_keys()](https://developer.wordpress.org/reference/functions/get_registered_meta_keys/)
- [WordPress Developer Resources - WP_Block_Type_Registry](https://developer.wordpress.org/reference/classes/wp_block_type_registry/)
- [ACF REST API Integration](https://www.advancedcustomfields.com/resources/wp-rest-api-integration/)
- [Pods Documentation](https://docs.pods.io/)
- [Meta Box Documentation - rwmb_get_registry](https://docs.metabox.io/functions/rwmb-get-registry/)
