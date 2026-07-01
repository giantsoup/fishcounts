<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>FishCounts</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>
@media only screen and (max-width: 600px) {
.wrapper,
.content,
.body,
.inner-body {
max-width: 100% !important;
width: 100% !important;
}

.footer {
max-width: 100% !important;
width: 100% !important;
}

.inner-body {
border-left: 0 !important;
border-radius: 0 !important;
border-right: 0 !important;
}

.content-cell {
padding: 24px 16px !important;
}

.table table {
margin: 24px 0 !important;
width: 100% !important;
}

.table th,
.table td {
font-size: 14px !important;
line-height: 18px !important;
padding: 8px 5px !important;
}
}

@media only screen and (max-width: 420px) {
.content-cell {
padding: 22px 12px !important;
}

.table th,
.table td {
font-size: 13px !important;
line-height: 17px !important;
padding: 7px 4px !important;
}
}

@media only screen and (max-width: 500px) {
.button {
width: 100% !important;
}
}
</style>
{!! $head ?? '' !!}
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width: 570px;">
<!-- Body content -->
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
