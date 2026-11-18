/* BABARPHANTOM — آپلودر APK با پشتیبانی تکه‌تکه (chunked)
 * چرا: هاست‌های cPanel معمولاً post_max_size=2M دارند و APK 20MB در یک درخواست جا نمی‌شود
 * (نتیجه: 404/413 یا $_FILES خالی). این فایل ابتدا یک‌جا تلاش می‌کند و اگر هاست نپذیرفت،
 * خودکار به حالت تکه‌تکه (تکه‌های ~1MB با بدنه خام) سوییچ می‌کند.
 * هیچ‌جا JSON به‌جای فایل فرستاده نمی‌شود؛ داده فایل خام یا multipart است.
 */
(function (w) {
  'use strict';

  function xhrPost(url, body, headers, cb) {
    var x = new XMLHttpRequest();
    x.open('POST', url, true);
    if (headers) for (var k in headers) if (headers.hasOwnProperty(k)) {
      try { x.setRequestHeader(k, headers[k]); } catch (e) {}
    }
    x.onload = function () { cb(null, x); };
    x.onerror = function () { cb(new Error('NETWORK'), x); };
    x.ontimeout = function () { cb(new Error('TIMEOUT'), x); };
    x.timeout = 120000;
    x.send(body);
  }

  function asJson(x) {
    try { return JSON.parse(x.responseText); } catch (e) { return null; }
  }

  function httpReason(x) {
    var j = asJson(x);
    if (j && j.msg) return j.msg;
    if (j && j.error) return j.error;
    switch (x.status) {
      case 0:   return 'ارتباط با سرور قطع شد (شبکه/فایروال)';
      case 401: return 'نشست منقضی شده — دوباره وارد پنل شو';
      case 403: return 'دسترسی رد شد (403)';
      case 404: return 'آدرس آپلود پیدا نشد (404) — مطمئن شو admin/apk_upload.php آپلود شده';
      case 413: return 'حجم درخواست بیشتر از حد هاست (413) — post_max_size را بالا ببر';
      case 500: return 'خطای داخلی سرور (500) — private/logs/upload.log را ببین';
    }
    var t = (x.responseText || '').replace(/<script[\s\S]*?<\/script>/gi, ' ').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    return 'HTTP ' + x.status + (t ? ' — ' + t.slice(0, 160) : '');
  }

  function mode(o) { return o.mode || 'auto'; }

  /* ── آپلود یک‌جا (multipart) ── */
  function singleUpload(o, fallback) {
    var fd = new FormData();
    fd.append('action', 'upload_apk');
    fd.append('ajax', '1');
    if (o.fields) for (var k in o.fields) if (o.fields.hasOwnProperty(k)) fd.append(k, o.fields[k]);
    fd.append('apk', o.file, o.file.name);

    var x = new XMLHttpRequest();
    x.open('POST', o.singleUrl || window.location.href, true);
    x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    x.upload.onprogress = function (ev) {
      if (ev.lengthComputable && o.onProgress) o.onProgress(ev.loaded, ev.total);
    };
    x.onload = function () {
      var j = asJson(x);
      if (x.status >= 200 && x.status < 300 && j && j.ok) {
        if (o.onProgress) o.onProgress(o.file.size, o.file.size);
        return o.onDone(j);
      }
      if (j && j.ok === false) return o.onError(j.msg || httpReason(x));
      // پاسخ HTML یا خطای هاست → تلاش تکه‌تکه
      if (fallback && (x.status === 0 || x.status === 404 || x.status === 413 || x.status >= 500 || !j)) {
        if (o.onStatus) o.onStatus('حالت یک‌جا پذیرفته نشد (' + (x.status || 'شبکه') + ') — سوییچ به آپلود تکه‌تکه…');
        return chunkedUpload(o);
      }
      o.onError(httpReason(x));
    };
    x.onerror = function () {
      if (fallback) { if (o.onStatus) o.onStatus('ارتباط قطع شد — تلاش با آپلود تکه‌تکه…'); return chunkedUpload(o); }
      o.onError('ارتباط با سرور قطع شد — اینترنت را چک کن');
    };
    x.send(fd);
  }

  /* ── آپلود تکه‌تکه ── */
  function chunkedUpload(o) {
    var ep = o.endpoint || 'apk_upload.php';
    var chunkSize = o.chunkSize || 1024 * 1024;
    var sent = 0, token = null, retries = 0;

    if (o.onStatus) o.onStatus('در حال آماده‌سازی آپلود…');
    var fd = new FormData();
    fd.append('name', o.file.name);
    fd.append('size', o.file.size);
    if (o.fields) for (var k in o.fields) if (o.fields.hasOwnProperty(k)) fd.append(k, o.fields[k]);

    xhrPost(ep + '?action=init&t=' + Date.now(), fd, { 'X-Requested-With': 'XMLHttpRequest' }, function (err, x) {
      if (err) return o.onError('ارتباط با سرور قطع شد — اینترنت را چک کن');
      var j = asJson(x);
      if (!j || !j.ok) return o.onError(httpReason(x));
      token = j.token;
      if (j.chunk_size && j.chunk_size > 0) chunkSize = Math.min(chunkSize, j.chunk_size);
      if (o.onStatus) o.onStatus('ارسال ' + Math.ceil(o.file.size / chunkSize) + ' تکه × ' + Math.round(chunkSize / 1024) + 'KB…');
      next();
    });

    function next() {
      if (sent >= o.file.size) return finish();
      var end = Math.min(sent + chunkSize, o.file.size);
      var blob = o.file.slice(sent, end);
      var idx = Math.floor(sent / chunkSize);
      xhrPost(ep + '?action=chunk&token=' + token + '&index=' + idx + '&t=' + Date.now(),
        blob, { 'Content-Type': 'application/octet-stream', 'X-Requested-With': 'XMLHttpRequest' },
        function (err, x) {
          if (err) {
            if (retries++ < 3) { if (o.onStatus) o.onStatus('قطعه ' + (idx + 1) + ' خطا داد — تلاش دوباره…'); return setTimeout(next, 700); }
            return o.onError('ارتباط در قطعه ' + (idx + 1) + ' قطع شد — اینترنت را چک کن');
          }
          var j = asJson(x);
          if (!j || !j.ok) {
            if (retries++ < 2) { return setTimeout(next, 700); }
            return o.onError('قطعه ' + (idx + 1) + ': ' + httpReason(x));
          }
          sent = end;
          if (o.onProgress) o.onProgress(sent, o.file.size);
          next();
        });
    }

    function finish() {
      if (o.onStatus) o.onStatus('بررسی فایل و ثبت در دیتابیس…');
      xhrPost(ep + '?action=finish&token=' + token + '&t=' + Date.now(), null,
        { 'X-Requested-With': 'XMLHttpRequest' }, function (err, x) {
          if (err) return o.onError('ارتباط هنگام نهایی‌سازی قطع شد — دوباره تلاش کن');
          var j = asJson(x);
          if (!j || !j.ok) return o.onError(httpReason(x));
          if (o.onProgress) o.onProgress(o.file.size, o.file.size);
          o.onDone(j);
        });
    }

    o._abort = function () {
      if (token) xhrPost(ep + '?action=abort&token=' + token, null, null, function () {});
    };
  }

  /* ── ورودی عمومی ── */
  w.apkUpload = function (o) {
    if (!o || !o.file) return;
    var limit = (o.postMax && o.postMax > 0) ? Math.max(128 * 1024, o.postMax - 1024 * 1024) : 1024 * 1024;
    var m = mode(o);
    if (m === 'chunked') return chunkedUpload(o);
    if (m === 'single') return singleUpload(o, false);
    if (o.file.size <= limit) return singleUpload(o, true);
    return chunkedUpload(o);
  };

  w.apkPing = function (endpoint, cb) {
    var fd = new FormData();
    fd.append('x', '1');
    xhrPost((endpoint || 'apk_upload.php') + '?action=ping&t=' + Date.now(), fd, { 'X-Requested-With': 'XMLHttpRequest' },
      function (err, x) {
        if (err) return cb({ ok: false, msg: 'ارتباط با سرور قطع شد' });
        var j = asJson(x);
        if (!j || !j.ok) return cb({ ok: false, msg: httpReason(x), status: x.status });
        cb(j);
      });
  };
})(window);
