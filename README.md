<div align="center">

# 🤖 kefu · 企业级 AI 智能客服平台

![kefu Logo](https://img.shields.io/badge/kefu-v1.0-5B5BF0?style=for-the-badge&logo=robot&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.0+-777BB4?style=for-the-badge&logo=php&logoColor=white)
![Webman](https://img.shields.io/badge/Webman-1.5+-00B388?style=for-the-badge)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![License](https://img.shields.io/badge/License-Apache_2.0-blue?style=for-the-badge)
![PRs](https://img.shields.io/badge/PRs-Welcome-brightgreen?style=for-the-badge)

**基于百度千帆大模型 · 多租户 SaaS 架构 · 一站式智能客服解决方案**

让企业用 AI 重塑客户服务体验 · 智能接待 + 人工接管 + 数据看板 + 工单流转 + 质检报表

[🌐 在线演示](https://kefu.xiaozhusho.top) · [📖 文档](docs/系统说明.md) · [🚀 快速开始](#-快速开始) · [💖 赞赏](#-赞赏支持)

</div>

---

## ✨ 项目简介

**kefu** 是一套面向企业的 **AI 智能客服系统**，采用多租户 SaaS 架构，提供从访客接入、智能接待、人工服务、工单流转到质检报表的完整闭环。基于百度千帆大模型，支持关键词 / 负面情绪 / 主动请求三重触发自动转人工，让企业用 AI 重塑客户服务体验。

### 🎯 核心亮点

| 能力 | 说明 |
| --- | --- |
| 🤖 **AI 智能接待** | 接入百度千帆 App 智能体，自动响应访客咨询 |
| 🔄 **三重转人工** | 关键词 + 负面情绪 + 主动请求，智能转人工接管 |
| 📊 **数据看板** | 8 大核心指标 + 4 张图表，实时掌握业务全局 |
| 🎫 **工单流转** | 从会话一键转工单，跨部门协作 + SLA 监控 |
| ✅ **会话质检** | 4 类规则（关键词/敏感词/标准/流程）自动质检 |
| 📚 **知识库** | 标准问 + 相似问法 + 命中率统计，AI 越用越聪明 |
| 🏢 **多租户** | 租户级数据隔离，支持 SaaS 化部署 |
| 🔌 **多渠道接入** | 微信公众号、小程序、API、Webhook 灵活扩展 |
| 🔐 **三态消息** | sending → delivered → read，保证消息可靠投递 |
| 🪟 **跨平台** | Windows 单进程可运行，Linux 多进程可水平扩展 |

---

## 📸 系统截图

### 🛡️ 管理后台 - 实时数据看板

8 大核心 KPI + 趋势图表 + 客服排行，实时掌控业务全局

![实时数据看板](docs/screenshots/screenshot-admin-dashboard.png)

### 🔐 管理后台 - 角色权限分配

灵活的 RBAC 权限模型，支持菜单权限 + API 权限的精细化配置

![角色权限分配](docs/screenshots/screenshot-admin-permissions.png)

### 🎫 管理后台 - 工单系统

从会话一键转工单，工单流转 + SLA 监控 + 自定义字段

![工单系统](docs/screenshots/screenshot-admin-tickets.png)

### 💼 客服工作台 - 留言管理

深色侧边栏 + 留言列表，处理离线客户留言

![客服工作台 - 留言管理](docs/screenshots/screenshot-agent-msgboard.png)

### 💬 访客端 Demo

访客侧聊天窗口，AI 智能接待 + 关键词转人工 + 评价反馈

![访客端 Demo](docs/screenshots/screenshot-visitor-demo.png)

---

## 🏗️ 系统架构

```
┌────────────────────────────────────────────────────────────────┐
│                         访客端                                  │
│   ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌─────────┐          │
│   │  Web   │  │  小程序  │  │公众号  │  │  App   │          │
│   └────┬────┘  └────┬────┘  └────┬────┘  └────┬────┘          │
└────────┼────────────┼────────────┼────────────┼───────────────┘
         │            │            │            │
         │ HTTPS / WebSocket / HTTP Polling      │
         ▼            ▼            ▼            ▼
┌────────────────────────────────────────────────────────────────┐
│                  Nginx（反向代理 + 静态资源）                     │
└────────────────────────────┬───────────────────────────────────┘
                             │
                             ▼
┌────────────────────────────────────────────────────────────────┐
│                Webman（HTTP + WS + 业务进程）                    │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐             │
│  │ VisitorChannel│ │ AgentChannel │ │ AdminChannel │  Channels  │
│  └──────┬───────┘ └──────┬───────┘ └──────┬───────┘             │
│         │                │                │                      │
│  ┌──────▼────────────────▼────────────────▼──────┐               │
│  │   Controllers（路由层 / 鉴权 / 参数校验）         │               │
│  └──────────────────────┬────────────────────────┘               │
│                         │                                        │
│  ┌──────────────────────▼────────────────────────┐               │
│  │   Services（业务逻辑：消息、会话、AI、统计）      │               │
│  └──────────────────────┬────────────────────────┘               │
│                         │                                        │
│  ┌──────────────┐ ┌─────▼─────┐ ┌──────────────┐                 │
│  │  Lib（Db、     │ │ Connection│ │ CronWorker   │                 │
│  │  Token、      │ │ Manager   │ │ RobotWorker  │                 │
│  │  Qianfan）    │ └───────────┘ └──────────────┘                 │
│  └──────┬────────┘     文件队列                                  │
└─────────┼────────────────────────────────────────────────────────┘
          │
          ▼
┌────────────────────────────────────────────────────────────────┐
│                  MySQL（kefu 库，47 张表）                        │
└────────────────────────────────────────────────────────────────┘
```

---

## 🛠️ 技术栈

### 后端

| 组件 | 版本 | 用途 |
| --- | --- | --- |
| PHP | 8.0+ | 开发语言 |
| Webman | 1.5+ | 高性能 HTTP/WS 框架（基于 Workerman） |
| MySQL | 5.7+ / 8.0 | 主数据库 |
| Redis | 6.x（可选） | 缓存 / 队列（生产推荐） |
| JWT | firebase/php-jwt | Token 签发与校验 |
| 百度千帆 | OpenAPI | AI 大模型 |

### 前端

| 组件 | 版本 | 用途 |
| --- | --- | --- |
| 原生 JS | ES6+ | 零构建 |
| HTML5 / CSS3 | - | UI 标记与样式 |
| design-system.css | 自研 | 设计系统化组件样式 |
| ECharts | 5.x | 数据可视化图表 |

### 部署

| 组件 | 用途 |
| --- | --- |
| Nginx | 反向代理 + HTTPS |
| PHP-FPM / Workerman | 应用服务 |
| Docker | 容器化部署 |

---

## 🚀 快速开始

### 环境要求

- **PHP** 8.0+（必需扩展：pdo_mysql、openssl、mbstring、curl）
- **MySQL** 5.7+ / 8.0
- **Composer** 2.x
- **操作系统**：Windows / Linux / macOS 均可

### 方式 A：传统部署（推荐新手）

```bash
# 1. 克隆代码
git clone https://github.com/your-org/kefu.git
cd kefu/server

# 2. 安装依赖
composer install --no-dev --optimize-autoloader

# 3. 创建数据库
mysql -u root -p -e "CREATE DATABASE kefu CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "CREATE USER 'kefu'@'localhost' IDENTIFIED BY 'adminkefu';"
mysql -u root -p -e "GRANT ALL PRIVILEGES ON kefu.* TO 'kefu'@'localhost';"

# 4. 初始化数据库（47 张表 + 默认数据）
mysql -u kefu -p kefu < sql/init.sql

# 5. 启动服务
php start.php start
```

### 方式 B：Docker 部署（推荐生产环境）

```bash
cd server
docker-compose -f deploy/docker-compose.yml up -d
```

完整 Docker 部署文档：[docs/部署方案.md](docs/部署方案.md)

### 访问入口

启动成功后访问：

| 系统 | URL | 测试账号 |
| --- | --- | --- |
| 商业化首页 | http://localhost:8787/ | 无需登录 |
| 管理后台 | http://localhost:8787/admin/login.html | `admin` / `admin123` |
| 客服工作台 | http://localhost:8787/agent/login.html | `agent01` / `admin123` |
| 访客端 Demo | http://localhost:8787/visitor-demo.html | 无需账号 |

---

## 📚 项目文档

| 文档 | 说明 |
| --- | --- |
| [docs/系统说明.md](docs/系统说明.md) | 完整技术文档（架构、API、数据库、模块设计）|
| [docs/操作说明书.md](docs/操作说明书.md) | 用户操作手册（管理后台 + 客服 + 访客）|
| [docs/部署方案.md](docs/部署方案.md) | 本地到线上的完整部署方案（含 Docker）|
| [docs/integration-guide.md](docs/integration-guide.md) | 多渠道接入指南（公众号 / 小程序 / API）|

---

## 🗄️ 数据库设计

### 表清单（共 47 张表）

| 模块 | 表数量 | 主要表 |
| --- | --- | --- |
| 租户与权限 | 6 | kefu_tenant, kefu_employee, kefu_role, kefu_permission, kefu_role_permission, kefu_dept |
| 客户管理 | 5 | kefu_customer, kefu_customer_group, kefu_customer_tag, kefu_blacklist |
| 访客端 | 2 | kefu_session_context, kefu_visitor_track |
| 会话模块 | 7 | kefu_session, kefu_message, kefu_evaluate, kefu_leave_msg, kefu_quick_reply |
| 机器人 | 9 | kefu_robot, kefu_knowledge, kefu_knowledge_similar, kefu_flow, kefu_entity |
| 工单 | 4 | kefu_ticket, kefu_ticket_log, kefu_ticket_template |
| 质检 | 2 | kefu_quality_rule, kefu_quality_result |
| 报表 | 3 | kefu_report_daily, kefu_agent_performance, kefu_report_custom |
| 通用 | 9 | kefu_config, kefu_sms, kefu_operation_log, kefu_file, kefu_ai_log |

### 设计原则

1. **所有业务表都带 `tenant_id`**：多租户隔离
2. **统一主键 `id BIGINT AUTO_INCREMENT`**
3. **统一时间戳字段**：`created_at` / `updated_at`
4. **JSON 字段用 TEXT 存储**：MySQL 5.7 兼容，应用层 JSON 编码 / 解码
5. **软删除**：用 `status` 字段替代 DELETE，保留审计

---

## 📡 API 文档

完整 API 在 [`server/config/route.php`](server/config/route.php) 中定义，共 **200+ 接口**。

### 通用响应格式

```json
{
  "code": 0,
  "msg": "ok",
  "data": { ... }
}
```

### 路由分组

| 前缀 | 中间件 | 用途 |
| --- | --- | --- |
| `/api/common/*` | 无 | 公共（登录、上传、字典） |
| `/api/admin/*` | Auth + Tenant | 管理后台 |
| `/api/agent/*` | Auth + Tenant + Sensitive | 客服工作台 |
| `/api/ticket/*` | Auth + Tenant | 工单 |
| `/api/visitor/*` | RateLimit + Sensitive | 访客端 |
| `/api/robot/*` | Auth + Tenant | 机器人知识库 |
| `/api/poll/*` | Auth | HTTP 长轮询 |
| `/api/channel/{code}/{id}` | 无 | 渠道 webhook |

---

## 🤝 贡献指南

我们欢迎所有形式的贡献！

### 提交流程

```bash
# 1. Fork 本仓库
# 2. 创建特性分支
git checkout -b feature/AmazingFeature

# 3. 提交变更
git commit -m "Add some AmazingFeature"

# 4. 推送到分支
git push origin feature/AmazingFeature

# 5. 提交 Pull Request
```

### 开发规范

- **代码风格**：PSR-12 基础 + 中文注释
- **类名**：PascalCase（如 `MessageService`）
- **方法名**：camelCase（如 `agentSend`）
- **数据库表名**：`kefu_` 前缀 + snake_case
- **测试要求**：每写一个 API → 写一个测试页面 → 测试通过后**删除测试页面**
- **禁止保留临时测试脚本**到代码库

### 报告 Bug

请使用 [GitHub Issues](https://github.com/your-org/kefu/issues) 提交 Bug，提供：
- 复现步骤
- 期望结果 / 实际结果
- 系统环境（PHP / MySQL / OS 版本）
- 错误日志

---

## 🗺️ 路线图

- [x] 多渠道接入（公众号 / 小程序 / API）
- [x] AI 智能接待（千帆 App）
- [x] 工单系统 + 质检报表
- [x] Docker 一键部署
- [x] 实时数据看板
- [ ] 视频客服（WebRTC）
- [ ] 工单 AI 自动分类
- [ ] 知识库向量检索（FAISS）
- [ ] 多语言支持（i18n）
- [ ] SaaS 多租户计费

---

## 🌟 Star History

如果这个项目对您有帮助，请给我们一个 ⭐️ Star！

---

## 📄 开源协议

本项目基于 **Apache License 2.0** 开源。

```
Apache License
Version 2.0, January 2004
http://www.apache.org/licenses/

Copyright 2026 kefu 开发团队 zero

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
```

---

## 💖 赞赏支持

如果 kefu 客服系统对您有帮助，欢迎扫描下方二维码支持开发者，您的每一份支持都是 kefu 开发团队 zero 持续优化的动力！

<div align="center">

### 微信支付

![赞赏 - 微信支付](docs/donate-wechat.png)

扫码备注：**Zero(**良)**

### 支付宝

![赞赏 - 支付宝](docs/donate-alipay.png)

扫码备注：**国良(**良)**

> **感谢您的支持！** kefu 会持续迭代更多企业级功能（多渠道、AI、报表、工单、知识库等），帮助企业用 AI 重塑客户服务。

</div>

---

## 📞 联系方式

| 项目 | 信息 |
| --- | --- |
| 🌐 **在线演示** | https://kefu.xiaozhusho.top |
| 👥 **开发团队** | kefu 开发团队 zero |
| 📧 **邮箱** | 619864585@qq.com |
| 📖 **项目文档** | https://kefu.xiaozhusho.top/docs/系统说明.md |

---

<div align="center">

**由 kefu 开发团队 zero 用 ❤️ 打造**

[⬆ 回到顶部](#-kefu--企业级AI-智能客服平台)

</div>