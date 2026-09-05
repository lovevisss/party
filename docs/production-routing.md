# 生产环境会议纪要列表 404 排查

当前列表地址为 `/minutes/party-branch` 和 `/minutes/party-government-joint`，路由名称均为 `minutes.type.index`；详情地址使用 UUID。代码为详情及相关操作增加了 UUID 路由约束，避免将类型名称识别为纪要 ID。

本地普通路由和编译路由测试均能匹配两个列表地址，不能仅凭浏览器日志断定生产环境原因。请在**线上站点实际使用的发布目录**执行：

```bash
php artisan route:list --path=minutes -v
```

应包含 `GET|HEAD minutes/{meetingType}`，对应 `MeetingMinuteController@index`。若缺失，请确认已发布最新 `routes/web.php` 和后端代码，而不只是 `public/build`。

发布本次修改后，在同一目录重新生成路由缓存：

```bash
php artisan route:clear
php artisan route:cache
php artisan route:list --path=minutes -v
```

若仍然 404，检查 Web 服务实际指向的发布目录是否正确、站点根目录是否为其 `public` 目录，以及是否有多个实例运行不同版本。若 PHP OPcache 禁用了时间戳校验，需通过部署平台重载对应 PHP-FPM 服务；使用常驻应用进程时也需重载进程。服务名称以服务器配置为准。

登录后分别打开两个列表。有权限的账号应正常显示页面；无权限应返回 403。若路由已存在但仍有问题，请保留该命令输出和对应请求时间的服务端日志进一步定位。

`preload but not used` 表示预加载资源未及时使用，与列表请求的 HTTP 404 应分别排查。`VM18 ... startTime` 无法仅凭匿名脚本堆栈定位来源，应先在浏览器开发者工具中确认脚本来源；当前应用源码没有 `reportAllChanges` 或 `startTime` 对应实现。
