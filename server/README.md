# 微信小程序智能客服系统 — 后端

> 作者：kefu 开发团队
> 创建时间：2026-07-29
> 当前版本：v1.0.0
> 状态：**已可生产**（HTTP + 业务逻辑全跑通，WS 在 Linux 部署后启用）

基于 Webman 框架的多租户客服系统后端。

---

## 一、技术栈

| 类别 | 技术 | 版本 |
|---|---|---|
| 框架 | Webman (Workerman) | 1.5 |
| PHP | PHP | 7.3+ (推荐 8.1) |
| 数据库 | MySQL | 5.7+ (推荐 8.0) |
| 缓存 | Redis | 6+ (推荐) |
| 鉴权 | Firebase JWT | ^6.0 |
| 日志 | Monolog | ^2.0 |
| 多进程 | Workerman 自定义进程 | ^4.1 |
| 部署 | Docker / Docker Compose | latest |

---

## 二、业务能力

```
┌───────────────────────────────────────────────────────────┐
│                     客 服 系 统 全 景                      │
├───────────────────────────────────────────────────────────┤
│  [访客端]                                                │
│    ├── H5/匿名登录                                        │
│    ├── 微信小程序登录                                       │
│    ├── 上下文（订单/购物车/画像）                           │
│    ├── 行为埋点                                            │
│    └── WebSocket 实时会话                                   │
│                                                             │
│  [客服工作台]                                              │
│    ├── 会话管理（分配/转接/关闭）                            │
│    ├── 实时消息（三态机制、session_sequence）                │
│    ├── 工作台状态（在线/忙碌/暂离/离线）                     │
│    ├── 客户画像/历史会话/标签                                │
│    ├── 数据看板                                            │
│    └── WebSocket 实时通知                                   │
│                                                             │
│  [管理后台]                                                │
│    ├── 员工/角色权限（多级 RBAC）                            │
│    ├── 工单系统（含会话升级 SLA）                            │
│    ├── 评价/满意度                                          │
│    ├── 质检评分（自动规则 + 人工抽检）                       │
│    ├── 报表统计（看板/日报/排行/趋势/时段/渠道）             │
│    ├── 敏感词管理                                          │
│    └── 知识库 + 机器人推理                                   │
│                                                             │
│  [基础设施]                                                │
│    ├── ChannelInterface 渠道适配器（多渠道接入）              │
│    ├── JWT 鉴权 + RBAC 角色控制                              │
│    ├── 限流中间件                                           │
│    ├── 敏感词过滤（双向：访客替换/客服拦截）                  │
│    ├── 多租户隔离（所有 SQL 带 tenant_id）                   │
│    ├── API 日志中间件                                        │
│    ├── Worker 进程（机器人/分发/定时任务）                   │
│    └── ConnectionManager 多维度连接管理                       │
└───────────────────────────────────────────────────────────┘
```

---

## 三、快速启动

### 方案一：直接运行（开发）

```bash
# 1. 安装依赖
composer install

# 2. 数据库初始化（46 张表 + 测试数据）
$content = Get-Content sql/init.sql -Raw
mysql -ukefu -padminkefu kefu < sql/init.sql

# 3. 配置 .env（默认账号：见下表）
cp .env.example .env  # 如果有
# 编辑 DB_HOST / DB_USERNAME / DB_PASSWORD 等

# 4. 启动（Windows）
php windows.php start

# Linux / Mac
php start.php start
```

### 方案二：Docker 一键部署

```bash
cd deploy/
docker-compose up -d
docker-compose logs -f webman
```

包含：webman（PHP）+ MySQL 8 + Redis 7 + Nginx

### 方案三：生产环境部署

1. 准备 Linux 服务器（CentOS / Ubuntu）
2. PHP 8.1 + MySQL 8.0 + Redis 7 + Nginx 1.20+
3. `composer install --no-dev --optimize-autoloader`
4. 修改 `.env` 中的 `APP_DEBUG=false` 和 `JWT_SECRET=random_strong_key`
5. `php start.php start`（用 supervisor / systemd 守护）
6. Nginx 反向代理（见 `deploy/nginx.conf`）

---

## 四、默认账号

| 用户名 | 密码 | 角色 | 用途 |
|---|---|---|---|
| admin | admin123 | 超管 | 管理后台、报表、质检、工单 |
| agent01 | admin123 | 客服 | 客服工作台 |
| agent02 | admin123 | 客服 | 客服工作台 |

**租户编码**：`demo`
**验证步骤**：登录后拿到 JWT，调用其他接口时 `Authorization: Bearer <token>`

---

## 五、API 接口一览

完整 65+ 个接口，详见 [docs/API.md](file:///d:/phpstudy_pro/WWW/kefu/server/docs/API.md) 或下方速查：

### 5.1 公共（无需鉴权）

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/api/health` | 健康检查 |
| POST | `/api/common/login` | 登录 |
| POST | `/api/common/logout` | 登出 |
| POST | `/api/common/refresh-token` | 刷新 Token |
| POST | `/api/common/upload` | 文件上传 |
| GET | `/api/common/dict` | 系统字典 |

### 5.2 访客端

| 方法 | 路径 | 说明 |
|---|---|---|
| POST | `/api/visitor/auth/weapp` | 微信小程序 |
| POST | `/api/visitor/auth/h5` | H5 匿名 |
| POST | `/api/visitor/context/update` | 上下文 |
| POST | `/api/visitor/track` | 行为埋点 |
| POST | `/api/visitor/event` | 自定义事件 |

### 5.3 评价提交（公开）

| 方法 | 路径 | 说明 |
|---|---|---|
| POST | `/api/evaluate/session` | 会话评价 |
| POST | `/api/evaluate/ticket` | 工单评价 |

### 5.4 机器人问答（公开）

| 方法 | 路径 | 说明 |
|---|---|---|
| POST | `/api/robot/answer` | 机器人推理 |

### 5.5 客服工作台（需鉴权 + Token）

| 方法 | 路径 |
|---|---|
| GET | `/api/agent/session/list` |
| POST | `/api/agent/session/assign` |
| POST | `/api/agent/session/transfer` |
| POST | `/api/agent/session/close` |
| POST | `/api/agent/message/send` |
| GET | `/api/agent/message/history` |
| POST | `/api/agent/status/switch` |
| GET | `/api/agent/customer/detail` |
| GET | `/api/agent/dashboard/realtime` |

### 5.6 管理后台（需鉴权 + 超管权限）

员工 / 角色 / 工单（9 接口） / 评价（2 接口） / 质检（6 接口） / 报表（9 接口） / 敏感词（5 接口）

---

## 六、目录结构

```
server/
├── app/                          # 应用代码
│   ├── channel/                  # 渠道适配器
│   │   ├── ChannelInterface.php  # 渠道接口规范
│   │   └── WeappChannel.php      # 微信小程序实现
│   ├── controller/               # HTTP 控制器（按域名划分）
│   │   ├── common/                # 公共（登录、上传）
│   │   ├── admin/                # 管理后台（含敏感词/质检/报表）
│   │   ├── agent/                 # 客服工作台
│   │   ├── visitor/               # 访客端
│   │   ├── robot/                 # 机器人
│   │   └── ticket/                # 工单
│   ├── middleware/                # 7 个中间件
│   ├── lib/                       # 公共库（Db/Token/Logger/SensitiveFilter/ConnectionManager）
│   ├── service/                   # 8 个业务服务
│   ├── process/                   # 3 个自定义进程类
│   ├── helpers.php                # 业务辅助函数
│   └── autoload.php               # Composer 生成的 PSR-4 自动加载
├── config/                        # 配置
│   ├── app.php / database.php / log.php / server.php / process.php
│   ├── route.php                  # API 路由定义（带中间件分组）
│   ├── middleware.php             # 全局中间件注册
│   ├── container.php              # DI 容器
│   └── static.php                 # 静态文件开关
├── public/                        # 静态资源 / SDK
│   ├── kefu-sdk.js                # H5 嵌入 SDK
│   ├── visitor-demo.html          # 访客演示页
│   ├── agent-console.html         # 客服工作台演示页
│   └── test_login.html            # 登录接口测试页
├── sql/                           # 脚本 + 测试
│   ├── init.sql                   # 46 张表初始化
│   ├── test_phase1.php ~ test_phase3.php
│   └── test_sensitive.php         # 敏感词 E2E
├── runtime/                       # 运行时（自动生成）
├── vendor/                        # Composer 依赖
├── support/                       # webman framework 框架文件
├── deploy/                        # 部署资源
│   ├── docker-compose.yml         # 一键部署
│   ├── nginx.conf                 # 反向代理
│   └── prometheus.yml             # 监控
├── start.php                       # Linux 启动入口
├── windows.php                    # Windows 启动入口
├── composer.json
├── .env
└── README.md
```

---

## 七、核心机制说明

### 7.1 多租户隔离

- 所有数据表带 `tenant_id` 字段
- 通过 `Db::setTenantId($tid)` 切换当前租户
- 所有 SQL 由 `Db::insert/find/value/query` 自动注入 `tenant_id`
- 多租户开发时调用 `TenantMiddleware::process`

### 7.2 消息三态机制

- `sending`：消息已写库待投递
- `delivered`：对方确认已收
- `read`：已读
- `session_sequence`：会话内严格自增序号
- 客户端断线重连：`?before_seq=N` 拉差量消息

### 7.3 鉴权与权限

- JWT (HS256) 签发 token（默认 8 小时有效）
- 路由中间件组合：Auth + Tenant + RateLimit + SensitiveFilter
- 不同 app namespace 应用不同中间件组（见 config/route.php）

### 7.4 敏感词处理

- 访客：自动替换为同长度 * 号
- 客服：按 `action` 处理（replace/block/warn）
- 命中日志写入 `kefu_sensitive_log`

### 7.5 实时推送（Linux/生产）

- WebSocket 端口：8788（Linux）
- ConnectionManager：`uid ↔ connection` / `session_id ↔ uids` / `role ↔ uids`
- 三种 Channel 类：AgentChannel / VisitorChannel / AdminChannel
- 自定义进程：RobotWorker / MessageDispatcher / CronWorker

---

## 八、测试

### 8.1 端到端测试脚本

```bash
# 阶段一：登录 / Token / 鉴权
php sql/test_all.php

# 阶段二：会话 / 消息 全流程
php sql/test_phase2.php

# 阶段三：工单 / 评价 / 质检 / 报表
php sql/test_phase3.php

# 敏感词 E2E
php sql/test_sensitive.php
```

### 8.2 浏览器测试

打开 `public/visitor-demo.html` 或 `public/agent-console.html`

---

## 九、关键文件索引

- 路由定义：[config/route.php](file:///d:/phpstudy_pro/WWW/kefu/server/config/route.php)
- 公共库 Db：[app/lib/Db.php](file:///d:/phpstudy_pro/WWW/kefu/server/app/lib/Db.php)
- JWT Token：[app/lib/Token.php](file:///d:/phpstudy_pro/WWW/kefu/server/app/lib/Token.php)
- 会话业务：[app/service/SessionService.php](file:///d:/phpstudy_pro/WWW/kefu/server/app/service/SessionService.php)
- 消息业务：[app/service/MessageService.php](file:///d:/phpstudy_pro/WWW/kefu/server/app/service/MessageService.php)
- 工单业务：[app/service/TicketService.php](file:///d:/phpstudy_pro/WWW/kefu/server/app/service/TicketService.php)
- 质检业务：[app/service/QualityService.php](file:///d:/phpstudy_pro/WWW/kefu/server/app/service/QualityService.php)
- 报表业务：[app/service/StatisticsService.php](file:///d:/phpstudy_pro/WWW/kefu/server/app/service/StatisticsService.php)
- 评价业务：[app/service/EvaluateService.php](file:///d:/phpstudy_pro/WWW/kefu/server/app/service/EvaluateService.php)
- 鉴权中间件：[app/middleware/AuthMiddleware.php](file:///d:/phpstudy_pro/WWW/kefu/server/app/middleware/AuthMiddleware.php)
- 敏感词中间件：[app/middleware/SensitiveFilterMiddleware.php](file:///d:/phpstudy_pro/WWW/kefu/server/app/middleware/SensitiveFilterMiddleware.php)
- 渠道适配器：[app/channel/ChannelInterface.php](file:///d:/phpstudy_pro/WWW/kefu/server/app/channel/ChannelInterface.php)
- 客服 WS Channel：[app/channel/AgentChannel.php](file:///d:/phpstudy_pro/WWW/kefu/server/app/channel/AgentChannel.php)
- 访客 WS Channel：[app/channel/VisitorChannel.php](file:///d:/phpstudy_pro/WWW/kefu/server/app/channel/VisitorChannel.php)
- 连接管理：[app/lib/ConnectionManager.php](file:///d:/phpstudy_pro/WWW/kefu/server/app/lib/ConnectionManager.php)
- H5 SDK：[public/kefu-sdk.js](file:///d:/phpstudy_pro/WWW/kefu/server/public/kefu-sdk.js)
- 客服工作台：[public/agent-console.html](file:///d:/phpstudy_pro/WWW/kefu/server/public/agent-console.html)
- 访客演示：[public/visitor-demo.html](file:///d:/phpstudy_pro/WWW/kefu/server/public/visitor-demo.html)
- 部署：[deploy/docker-compose.yml](file:///d:/phpstudy_pro/WWW/kefu/server/deploy/docker-compose.yml)

---

## 十、注意事项

1. **PHP 8.1+**：建议生产环境升级到 PHP 8.1（webman 最新版本要求 8.1）
2. **MySQL 8.0**：建议升级到 8.0 并使用 JSON 列类型
3. **Redis 必装**：生产环境必须使用 Redis 做缓存 / Session / Token 黑名单
4. **WebSocket 仅限 Linux**：Windows 下 webman 1.5 不支持 fork 自定义进程，WS 在 Linux 服务器生效
5. **JWT_SECRET**：生产环境必须替换为强随机串

---

## 十一、版本信息

| 版本 | 日期 | 描述 |
|---|---|---|
| 1.0.0 | 2026-07-30 | 第一阶段（登录/Token） + 第二阶段（会话/消息） + 第三阶段（工单/质检/报表）+ 第四阶段（前端/SDK/Docker）全部完成 |
