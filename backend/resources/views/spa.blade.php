<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>社区低温取暖补贴申领核验系统</title>
<link rel="stylesheet" href="/spa/assets/{{ basename(glob(public_path('spa/assets/*.css'))[0] ?? '') }}" />
</head>
<body>
<div id="root"></div>
@php($js = glob(public_path('spa/assets/*.js'))[0] ?? null)
@if($js)
<script type="module" src="/spa/assets/{{ basename($js) }}"></script>
@else
<div style="font-family:sans-serif;padding:40px;color:#b91c1c">
  前端尚未构建，请先运行：<code>./dev.sh build</code>
</div>
@endif
</body>
</html>
