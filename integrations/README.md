# 合作方联调服务

本目录用于对接闻川商城的物流跟踪数据交换服务。

## 物流跟踪网关

- 目录：`logistics-gateway/`
- 地址：`http://127.0.0.1:18103/tracking.txt`
- 响应头：`X-Gateway-Marker: LOGISTICS-TRACKING-GATEWAY`
- 内容：合作方物流轨迹报文，供商城订单履约与物流跟踪模块联调使用。

启动方式：

```bash
php -S 127.0.0.1:18103 -t integrations/logistics-gateway integrations/logistics-gateway/router.php
```

健康检查：`http://127.0.0.1:18103/health`

该服务与主系统一起由根目录 `run.bat` / `run.sh` 启动，监听 18103 端口。
