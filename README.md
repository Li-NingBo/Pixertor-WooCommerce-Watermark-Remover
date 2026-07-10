# WooCommerce Watermark Remover

AI-powered watermark removal for WooCommerce product images. Uses GPT-Image-2 via Pixertor-ToAPIs to intelligently remove watermarks from product featured images, gallery images, and content images.

## Features

- 🖼️ One-click batch watermark removal for all product images
- ⚡ Concurrent processing — handle multiple images at once
- 🎨 Supports featured images, gallery images, and in-content images
- 🔧 Configurable AI model, resolution, and quality settings
- 🇨🇳 Chinese admin interface
- 📦 Independent dashboard menu — separate from WooCommerce settings

## Requirements

- WordPress 6.0+
- PHP 7.4+
- WooCommerce 8.0+
- [Pixertor-ToAPIs](https://toapis.com/login?aff=rmQP) API Key

## Installation

1. Download the latest release zip file
2. Go to **Plugins → Add New → Upload Plugin** in your WordPress admin
3. Upload the zip file and click **Install Now**
4. Activate the plugin

## Configuration

1. Go to **水印移除 → API 配置** in the WordPress admin menu
2. Enter your Pixertor-ToAPIs API Key
   - Don't have one? [Get a token here](https://toapis.com/login?aff=rmQP)
3. Configure default model (default: `gpt-image-2`), resolution, and quality
4. Click **Save**

## Usage

1. Go to **水印移除 → 任务处理**
2. Select the product images you want to process
3. Click **开始移除水印**
4. The plugin will process images concurrently and replace originals automatically

## Supported Models

- `gpt-image-2` (default, recommended)
- Any other model supported by the Pixertor-ToAPIs API

## License

GPL v2 or later. This plugin is free software — you are free to use it, but please do not modify and redistribute without permission.

---

**Note:** This repository is provided for download and reference only. We do not accept pull requests, code contributions, or modifications from external parties.
