# Pixertor - WooCommerce Watermark Remover

AI-powered watermark removal for WooCommerce product images. Uses GPT-Image-2 via Pixertor-ToAPIs to intelligently remove watermarks from product featured images, gallery images, and content images.

## 下载安装

👉 **[下载完整压缩包 (v1.3.0)](https://github.com/Li-NingBo/Pixertor-WooCommerce-Watermark-Remover/releases/download/v1.3.0/pixertor-woo-watermark-remover.zip)**

下载后 → WordPress 后台 → 插件 → 安装插件 → 上传插件 → 上传 zip 文件 → 激活即可使用。

---

## 环境要求

- WordPress 6.0+
- PHP 7.4+
- WooCommerce 8.0+
- [Pixertor-ToAPIs](https://toapis.com/login?aff=rmQP) API Key

## 配置指南

1. 激活后前往 **水印移除 → API 配置**
2. 输入你的 Pixertor-ToAPIs API Key
   - 还没有 Key？[点击获取 Token](https://toapis.com/login?aff=rmQP)
3. 配置模型（默认 `gpt-image-2`）、分辨率、画质
4. 点击 **保存设置**

## 使用说明

1. 前往 **水印移除 → 任务处理**
2. 勾选需要去除水印的商品图片
3. 点击 **开始移除水印**
4. 插件将并发处理，完成后自动替换原图

## 功能特性

- 🖼️ 一键批量去除商品图片水印
- ⚡ 并发处理，支持多张图片同时进行
- 🎨 支持主图、画廊图、内容内嵌图
- 🔧 可配置 AI 模型、分辨率、画质
- 🇨🇳 全中文管理界面
- 📦 独立仪表盘菜单，不依赖 WooCommerce 子菜单

## 支持的模型

- `gpt-image-2`（默认，推荐）
- Pixertor-ToAPIs 支持的其他模型
## 面板截图
<img width="1920" height="953" alt="image" src="https://github.com/user-attachments/assets/26786dbd-e2b4-4c7a-a6b6-dc05048982a9" />

## License

GPL v2 or later.

---

**注意：** 本仓库仅供下载和使用。不接受 Pull Request 或外部代码贡献。
