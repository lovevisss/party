# 生产环境截止时间接口 404 排查

纪要表单调用 `GET /minutes/deadline?meeting_end_at=2026-09-24T10%3A41`。接口受登录保护，正常登录请求应返回含 `due_at` 的 JSON；无登录态时应跳转登录，而不是返回 404。该接口由 `MinuteDeadlineController` 和 `routes/web.php` 中的 `minutes.deadline` 路由提供。

在**线上站点实际使用的发布目录**只读核查：

```bash
php artisan route:list --path=minutes/deadline -v
test -f app/Http/Controllers/MinuteDeadlineController.php
grep -n "minutes/deadline" routes/web.php
```

路由应显示 `GET|HEAD minutes/deadline`、`minutes.deadline`、`MinuteDeadlineController` 和 `auth` 中间件。若路由或控制器缺失，需发布完整的后端代码，而不只是 `public/build` 前端资源；同时确认 Web 服务指向同一发布目录。

代码齐全但路由仍缺失时，在发布完成后重新生成路由缓存：

```bash
php artisan route:clear
php artisan route:cache
php artisan route:list --path=minutes/deadline -v
```

若命令显示路由而浏览器仍返回 404，检查站点是否有多个实例或旧发布目录，以及 PHP OPcache／常驻进程是否仍加载旧代码；通过部署平台重载实际运行的 PHP 服务。最后用已登录账号刷新纪要表单并修改会议结束时间，确认网络请求返回 200，页面显示第三个工作日的 `23:59:59`。不要把无登录态请求的跳转误判为接口故障。
