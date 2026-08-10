# 渠道接入指南

> 介绍如何将各种平台接入到 kefu 客服系统。

## 一、API 渠道（通用 HTTP 接口）

任何能发起 HTTP POST 请求的应用都可以使用 API 渠道接入。

### 1. 在管理后台创建 API 渠道账号

进入：**多渠道接入 → 选择「API 渠道」 → 配置账号 → + 新增账号**

记下生成的 **API Key**（无则自动生成）。

### 2. 调用 API 端点

```
POST /api/channel/api/{account_id}
Host: https://kefu.xiaozhusho.top
Headers:
  Content-Type: application/json
  X-API-Key: {你的API Key}
  (或) Authorization: Bearer {你的API Key}

Body (JSON):
{
  "visitor_id": "user_12345",          // 必填：访客唯一 ID
  "session_id": "s_xxx",               // 可选：不传则自动生成
  "name": "李雷",                      // 可选：访客姓名
  "avatar": "https://...",             // 可选：访客头像 URL
  "content": "你好，我想咨询...",       // 必填：消息内容
  "msg_type": "text",                  // 可选：默认 text
  "message_id": "msg_unique_001",      // 可选：幂等键
  "custom_fields": {                   // 可选：自定义字段（订单等业务字段）
    "order_no": "ORD-2026-001",
    "vip_level": "gold",
    "source": "iOS APP v3.2"
  }
}
```

返回：

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "session_id": "s_api_32a9c2f93d37",
    "message_id": "msg_unique_001",
    "agent": { "id": 1, "name": "客服小王" }  // 分配的客服
  }
}
```

### 3. 支持的字段类型
- `text` / `textarea`（文本）
- `select`（下拉）
- `radio`（单选）
- `checkbox`（多选）
- `date`（日期）
- `number`（数字）
- `email` / `phone`

### 4. PHP 调用示例

```php
$ch = curl_init('https://kefu.xiaozhusho.top/api/channel/api/1');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-API-Key: demo_key_xxxxx',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'visitor_id' => 'user_12345',
    'name' => '李雷',
    'avatar' => 'https://example.com/avatar.jpg',
    'content' => '你好，请问产品价格？',
    'custom_fields' => ['order_no' => 'ORD-001'],
]));
echo curl_exec($ch);
```

### 5. Node.js 调用示例

```javascript
const fetch = require('node-fetch');
fetch('https://kefu.xiaozhusho.top/api/channel/api/1', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-API-Key': 'demo_key_xxxxx',
  },
  body: JSON.stringify({
    visitor_id: 'user_12345',
    name: '李雷',
    content: '你好',
    custom_fields: { order_no: 'ORD-001' },
  }),
});
```

---

## 二、微信小程序接入

### 1. 官方文档
- 小程序客服消息：https://developers.weixin.qq.com/miniprogram/introduction/custom.html
- 服务端 API：https://developers.weixin.qq.com/miniprogram/dev/api-backend/open-api/customer-message/

### 2. 接入步骤

1. **配置小程序**：
   - 微信小程序后台 → 开发 → 开发设置 → 消息推送 → 启用并填写 URL/Token/EncodingAESKey
   - 把这些值填到 kefu **多渠道接入 → 小程序主号 → 配置账号** 里

2. **Kefu 后台接收消息**：
   - Kefu 提供 `/api/channel/weapp/webhook` 接口接收微信客服消息
   - 验证消息签名（微信加密），解密 AES 消息
   - 写入会话、推送客服工作台

3. **客服回复**：
   - Kefu 后台用 `kefu_message` API 发回消息，Kefu 自动调用 `https://api.weixin.qq.com/cgi-bin/message/custom/send?access_token=xxx` 转发到微信

### 3. 访客在微信内发起咨询
- 小程序用户点击客服按钮 → 微信后台接收 → 推送到 Kefu → 客服在 Kefu 工作台回复 → Kefu 调用微信 API 回复用户

### 4. Python 集成示例（业务系统主动发消息）

```python
import requests

url = 'https://kefu.xiaozhusho.top/api/channel/api/{account_id}'
headers = {
    'Content-Type': 'application/json',
    'X-API-Key': 'your_api_key',
}
data = {
    'visitor_id': 'wx_openid_xxx',
    'name': '微信用户',
    'avatar': 'https://thirdwx.qlogo.cn/...',
    'content': '您的订单已发货',
}
requests.post(url, json=data, headers=headers).json()
```

---

## 三、微信公众号 / 服务号接入

### 1. 官方文档
- 微信客服消息：https://developers.weixin.qq.com/doc/offiaccount/Message_Management/Service_Center_messages.html

### 2. 接入步骤
1. 在微信公众平台启用"客服消息"功能
2. 配置服务器 URL / Token / EncodingAESKey → 填到 Kefu 后台
3. 用户发送消息 → 微信推送 → Kefu 接收 → 客服回复

### 3. 关键区别
- 微信公众号用户**48 小时内**有交互才能接收客服消息（被动回复限制）
- 微信小程序用户可主动发起咨询（按钮触发）

---

## 四、微信视频号接入

### 1. 官方文档
- 视频号助手：https://channels.weixin.qq.com/
- 视频号小店客服：https://docs.qq.com/doc/DZFNvVHFHT1BwR2Vt

### 2. 接入步骤
1. 视频号开通"私信客服"功能
2. 配置 webhook 接收私信事件
3. Kefu 提供 `/api/channel/wechat_channel/webhook` 接收

### 3. 客服回复
- 视频号私信 API：使用 access_token + openid 回复
- Kefu 后台自动调用此 API

---

## 五、抖音 / 小程序接入

### 1. 官方文档
- 抖音开放平台：https://developer.open-douyin.com/
- 客服消息 API：https://developer.open-douyin.com/docs/resource/zh-CN/mini-app/develop/server/im/send-message

### 2. 接入步骤
1. 在抖音开发者后台创建小程序
2. 配置客服消息 webhook URL：`https://kefu.xiaozhusho.top/api/channel/douyin/webhook`
3. Kefu 后台填入 client_id / client_secret

---

## 六、APP SDK（原生 iOS/Android）

### 1. iOS 集成示例（Swift）

```swift
let url = URL(string: "https://kefu.xiaozhusho.top/api/channel/api/1")!
var request = URLRequest(url: url)
request.httpMethod = "POST"
request.setValue("application/json", forHTTPHeaderField: "Content-Type")
request.setValue("your_api_key", forHTTPHeaderField: "X-API-Key")

let body: [String: Any] = [
    "visitor_id": "ios_user_123",
    "name": "iPhone 用户",
    "avatar": "https://...",
    "content": "你好，我有问题",
    "custom_fields": [
        "platform": "iOS",
        "app_version": "3.2.1"
    ]
]
request.httpBody = try? JSONSerialization.data(withJSONObject: body)

URLSession.shared.dataTask(with: request) { data, response, error in
    // handle response
}.resume()
```

### 2. Android 集成示例（Kotlin）

```kotlin
import okhttp3.*

val client = OkHttpClient()
val body = RequestBody.create(
    MediaType.parse("application/json"),
    """{
        "visitor_id": "android_user_123",
        "name": "Android 用户",
        "content": "你好，我有问题",
        "custom_fields": {
            "platform": "Android",
            "app_version": "3.2.1"
        }
    }"""
)
val request = Request.Builder()
    .url("https://kefu.xiaozhusho.top/api/channel/api/1")
    .header("X-API-Key", "your_api_key")
    .post(body)
    .build()

client.newCall(request).enqueue(object : Callback {
    override fun onFailure(call: Call, e: IOException) {}
    override fun onResponse(call: Call, response: Response) {}
})
```

---

## 七、嵌入式 Web Widget

### 1. 嵌入到任意网页

```html
<!-- 把以下代码放到 </body> 之前 -->
<script src="https://kefu.xiaozhusho.top/widget/kefu.js" async></script>
<script>
  window.KefuWidget.init({
    tenantId: 1,             // 租户 ID
    autoOpen: true,           // 嵌入页面立即展开对话窗口（推荐）
    // 可选：访客预填信息（API 模式）
    name: '李雷',             // 访客姓名
    avatar: 'https://...',   // 访客头像
  });
</script>
```

### 2. 访客提交自定义字段

```javascript
// 在用户填写表单后调用，把信息同步到客服端
KefuWidget.submitCustomFields({
  order_no: 'ORD-2026-001',
  vip_level: 'gold'
});
```

### 3. 监听事件

```javascript
KefuWidget.on('send', function(msg) {
  console.log('访客发送：', msg);
});
```

---

## 八、消息格式约定

所有渠道收到的消息都遵循统一格式（kefu_message 表）：

| 字段 | 类型 | 说明 |
|---|---|---|
| sender_type | string | `visitor` / `agent` / `ai` / `system` |
| sender_id | string | 发送者 ID |
| sender_name | string | 发送者姓名 |
| sender_avatar | string | 头像 URL |
| content | text | 消息内容 |
| msg_type | string | text / image / file / voice |
| ext_json | JSON | 自定义扩展字段（含 custom_fields） |
| created_at | datetime | 发送时间 |

---

## 九、安全建议

1. **API Key 必须保密**，不要硬编码到客户端代码
2. 启用 HTTPS（生产环境必须）
3. 开启 IP 白名单（在多渠道接入 → 配置账号里设置）
4. 定期轮换 API Key
5. 启用 message_id 幂等防止消息重复

---

## 十、调试技巧

- 在 `多渠道接入 → 配置账号` 页面可以看到每个账号的"最近错误"和"验通时间"
- API 渠道消息记录可在 `kefu_channel_message` 表查看
- 失败消息会回写 `last_error` 字段

如有问题，请联系客服支持。