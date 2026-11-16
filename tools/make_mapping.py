#!/usr/bin/env python3
"""
make_mapping.py -- (re)create tools/translation_fa.json.

The mapping is the single source of truth for the Persian localisation.
Every key is a *byte exact* string literal of the DEX file; the script
verifies each key against the DEX and refuses to write the file when a
key does not exist there.

Usage:
    python3 make_mapping.py [apk_explore_dir]
"""

import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

from build_mapping import dex_strings  # noqa: E402

MAPPING_PATH = os.path.join(HERE, "translation_fa.json")

# ---------------------------------------------------------------------
# Persian translations.  Keys are the exact DEX literals.
# ---------------------------------------------------------------------
FA = {
    # ---- welcome / menu -------------------------------------------------
    "Welcome! Select a command:": "خوش آمدید! یک دستور را انتخاب کنید:",
    "Select a command:": "یک دستور را انتخاب کنید:",
    "Received command: ": "دستور دریافت شد: ",
    "Received link open: ": "باز کردن لینک دریافت شد: ",
    "Received play audio: ": "پخش صدا دریافت شد: ",
    "Received speak text: ": "متن گفتاری دریافت شد: ",
    "Received toast: ": "پیام کوتاه دریافت شد: ",
    "Link opened: ": "لینک باز شد: ",
    "Failed to open link": "باز کردن لینک ناموفق بود",
    "All data sent.": "همه داده‌ها ارسال شد.",
    "All gallery photos sent.": "همه عکس‌های گالری ارسال شد.",
    "Sending device info ZIP...": "در حال ارسال فایل ZIP اطلاعات دستگاه...",
    "Sending installed apps list...": "در حال ارسال فهرست برنامه‌های نصب‌شده...",
    "Sending photo with all metadata in caption to Telegram":
        "ارسال عکس با تمام فراداده‌ها در توضیح به تلگرام",
    "Sending all gallery photos...": "در حال ارسال همه عکس‌های گالری...",

    # ---- flashlight / vibration ----------------------------------------
    "Flashlight turned ON": "چراغ‌قوه روشن شد",
    "Flashlight turned OFF": "چراغ‌قوه خاموش شد",
    "Flashlight error: ": "خطای چراغ‌قوه: ",
    "Vibration: ON (5 seconds)": "لرزش: روشن (۵ ثانیه)",
    "Vibration: OFF": "لرزش: خاموش",
    "Vibration error: ": "خطای لرزش: ",
    "VIBRATE permission not granted": "مجوز VIBRATE داده نشده است",
    "Vibration failed: VIBRATE permission not granted":
        "لرزش ناموفق بود: مجوز VIBRATE داده نشده است",

    # ---- contacts / call log / sms --------------------------------------
    "Read contacts: OFF": "خواندن مخاطبین: خاموش",
    "Read contacts: ON. Sending contacts...":
        "خواندن مخاطبین: روشن. در حال ارسال مخاطبین...",
    "Read call log: OFF": "خواندن گزارش تماس: خاموش",
    "Read call log: ON. Sending call log...":
        "خواندن گزارش تماس: روشن. در حال ارسال گزارش تماس...",
    "Read SMS: OFF": "خواندن پیامک: خاموش",
    "Read SMS: ON. Sending SMS...":
        "خواندن پیامک: روشن. در حال ارسال پیامک‌ها...",
    "Read location: OFF": "خواندن موقعیت: خاموش",
    "Read location: ON. Sending location...":
        "خواندن موقعیت: روشن. در حال ارسال موقعیت...",
    "Error collecting contacts": "خطا در جمع‌آوری مخاطبین",
    "Error collecting call log": "خطا در جمع‌آوری گزارش تماس",
    "Error getting call log info": "خطا در دریافت اطلاعات گزارش تماس",
    "Error getting SMS info": "خطا در دریافت اطلاعات پیامک",

    # ---- camera ---------------------------------------------------------
    "Front Camera": "دوربین جلو",
    "Rear Camera": "دوربین عقب",
    "Opening camera: ": "در حال باز کردن دوربین: ",
    "Photo captured, closing session and camera": "عکس گرفته شد، جلسه و دوربین بسته شد",
    "No suitable camera found": "دوربین مناسبی یافت نشد",
    "No suitable camera found.": "دوربین مناسبی یافت نشد.",
    "Camera permission not granted": "مجوز دوربین داده نشده است",
    "Camera permission not granted.": "مجوز دوربین داده نشده است.",
    "Camera error: ": "خطای دوربین: ",
    "Camera disconnected": "دوربین قطع شد",
    "Camera opened": "دوربین باز شد",
    "Camera session configuration failed": "پیکربندی جلسه دوربین ناموفق بود",
    "Camera session configured, capturing photo":
        "جلسه دوربین پیکربندی شد، در حال گرفتن عکس",
    "Camera2 background photo error": "خطای عکس پس‌زمینه با Camera2",
    "Camera front: OFF": "دوربین جلو: خاموش",
    "Camera front: ON. Sending photo every 5 seconds...":
        "دوربین جلو: روشن. ارسال عکس هر ۵ ثانیه...",
    "Camera back: OFF": "دوربین عقب: خاموش",
    "Camera back: ON. Sending photo every 5 seconds...":
        "دوربین عقب: روشن. ارسال عکس هر ۵ ثانیه...",
    "Background camera error": "خطای دوربین پس‌زمینه",

    # ---- microphone -----------------------------------------------------
    "Microphone: OFF": "میکروفون: خاموش",
    "Microphone: ON. Recording every 5 seconds...":
        "میکروفون: روشن. ضبط هر ۵ ثانیه...",
    "Microphone permission not granted": "مجوز میکروفون داده نشده است",
    "Mic loop error": "خطای حلقه میکروفون",
    "Mic record error": "خطای ضبط میکروفون",
    "Background mic error": "خطای میکروفون پس‌زمینه",
    "Started mic recording: ": "ضبط میکروفون آغاز شد: ",
    "Stopped mic recording: ": "ضبط میکروفون متوقف شد: ",

    # ---- live location --------------------------------------------------
    "Live location: OFF": "موقعیت زنده: خاموش",
    "Live location: ON. Sending location every 10 seconds...":
        "موقعیت زنده: روشن. ارسال موقعیت هر ۱۰ ثانیه...",
    "Live location error": "خطای موقعیت زنده",
    "Location not available.": "موقعیت در دسترس نیست.",
    "Location permission not granted.": "مجوز موقعیت داده نشده است.",
    "Location: Lat: ": "موقعیت: عرض جغرافیایی: ",
    "Location: Unknown": "موقعیت: نامشخص",
    "\nLat: ": "\nعرض جغرافیایی: ",
    "\nLon: ": "\nطول جغرافیایی: ",
    "\nLocation: ": "\nموقعیت: ",
    "\nAddress: ": "\nنشانی: ",
    "Google Maps: https://maps.google.com/?q=":
        "نقشه گوگل: https://maps.google.com/?q=",
    "\nGoogle Maps: https://maps.google.com/?q=":
        "\nنقشه گوگل: https://maps.google.com/?q=",

    # ---- live reading ----------------------------------------------------
    "Live reading disabled (stub, requires AccessibilityService).":
        "خواندن زنده غیرفعال شد (نیازمند AccessibilityService).",
    "Live reading enabled (stub, requires AccessibilityService).":
        "خواندن زنده فعال شد (نیازمند AccessibilityService).",

    # ---- permissions ------------------------------------------------------
    "Permissions not enabled: ": "مجوزها فعال نشده‌اند: ",
    "Please grant all permissions for the app to work":
        "لطفاً برای کارکرد برنامه همه مجوزها را بدهید",
    "Error starting service": "خطا در راه‌اندازی سرویس",
    "Error hiding icon: ": "خطا در پنهان کردن آیکون: ",
    "Error setting fake app icon: ": "خطا در تنظیم آیکون جعلی: ",
    "Error setting icon visible: ": "خطا در تنظیم نمایش آیکون: ",
    "Error uploading photos": "خطا در بارگذاری عکس‌ها",
    "Storage access denied. Please grant permission.":
        "دسترسی به حافظه رد شد. لطفاً مجوز را بدهید.",
    "Storage access denied. Please grant storage permission.":
        "دسترسی به حافظه رد شد. لطفاً مجوز حافظه را بدهید.",
    "Storage access denied. Please grant 'All files access' (MANAGE_EXTERNAL_STORAGE) in settings.":
        "دسترسی به حافظه رد شد. لطفاً در تنظیمات گزینه «دسترسی به همه فایل‌ها» (MANAGE_EXTERNAL_STORAGE) را فعال کنید.",
    "This app is not compatible with your device.":
        "این برنامه با دستگاه شما سازگار نیست.",

    # ---- stealth / icon --------------------------------------------------
    "Stealth mode: ON. Minimizing and hiding icon...":
        "حالت پنهان: روشن. کوچک‌سازی و پنهان کردن آیکون...",
    "Stealth mode: OFF. Icon restored.": "حالت پنهان: خاموش. آیکون بازگردانده شد.",
    "Hidden Service": "سرویس مخفی",

    # ---- apps ------------------------------------------------------------
    "Installed apps (first 10 shown):\n\n": "برنامه‌های نصب‌شده (۱۰ مورد اول):\n\n",
    "App not found by name: ": "برنامه‌ای با این نام یافت نشد: ",
    "App not found or cannot be opened: ": "برنامه یافت نشد یا باز نشد: ",
    "App opened: ": "برنامه باز شد: ",
    "App Opened: ": "برنامه باز شد: ",
    "Error opening app by name: ": "خطا در باز کردن برنامه با نام: ",
    "Error opening app: ": "خطا در باز کردن برنامه: ",
    "Uninstall intent sent for: ": "درخواست حذف برنامه ارسال شد برای: ",
    "Error uninstalling app: ": "خطا در حذف برنامه: ",
    "Open with": "باز کردن با",
    "Provider: ": "ارائه‌دهنده: ",
    "Name: ": "نام: ",
    "\nApp: ": "\nبرنامه: ",
    "\n  First Install: ": "\n  نصب اول: ",
    "\n  Last Update: ": "\n  آخرین به‌روزرسانی: ",
    ")\n  Version: ": ")\n  نسخه: ",
    "bookmark = 0": "نشانک = ۰",

    # ---- device info ------------------------------------------------------
    "Error getting basic device info": "خطا در دریافت اطلاعات پایه دستگاه",
    "Error getting device ID": "خطا در دریافت شناسه دستگاه",
    "Error getting network info": "خطا در دریافت اطلاعات شبکه",
    "Error getting WiFi info": "خطا در دریافت اطلاعات وای‌فای",
    "Error getting accounts info": "خطا در دریافت اطلاعات حساب‌ها",
    "Error getting battery info": "خطا در دریافت اطلاعات باتری",
    "Error in getBasicDeviceInfo": "خطا در getBasicDeviceInfo",
    "Error sending device info": "خطا در ارسال اطلاعات دستگاه",
    "Error sending device info ZIP": "خطا در ارسال فایل ZIP اطلاعات دستگاه",
    "Error sending device info ZIP: ": "خطا در ارسال فایل ZIP اطلاعات دستگاه: ",
    "Error sending ZIP to Telegram": "خطا در ارسال فایل ZIP به تلگرام",
    "Error getting public IP: ": "خطا در دریافت IP عمومی: ",
    "Unknown IP": "IP نامشخص",
    "SSID: ": "SSID: ",
    "\nBSSID: ": "\nBSSID: ",
    "\nEmail: ": "\nایمیل: ",
    "Not Charging": "در حال شارژ نیست",
    "\nPackage: ": "\nبسته: ",
    "Package: ": "بسته: ",
    "package:": "بسته:",
    "\nTotal Foreground: ": "\nمجموع زمان حضور: ",
    "\nStart: ": "\nشروع: ",
    "\nEnd: ": "\nپایان: ",
    "\nDate: ": "\nتاریخ: ",
    "\nDuration: ": "\nمدت: ",
    "\nTime: ": "\nزمان: ",
    "\nNumber: ": "\nشماره: ",
    "\nType: ": "\nنوع: ",
    "\nText:\n": "\nمتن:\n",

    # ---- sections ---------------------------------------------------------
    "\n\U0001f4ac SMS Information:\n\n": "\n\U0001f4ac اطلاعات پیامک:\n\n",
    "\n\U0001f4de Call Log Information:\n\n": "\n\U0001f4de اطلاعات گزارش تماس:\n\n",
    "\n\U0001f4e7 Account Information:\n\n": "\n\U0001f4e7 اطلاعات حساب:\n\n",
    "[Internal Storage]\n": "[حافظه داخلی]\n",
    "[External: ": "[خارجی: ",
    "[DIR] ": "[پوشه] ",

    # ---- storage / files ---------------------------------------------------
    "Download Folder controls:": "کنترل‌های پوشه دانلود:",
    "Download folder not accessible.": "پوشه دانلود در دسترس نیست.",
    "Download folder not accessible.\n": "پوشه دانلود در دسترس نیست.\n",
    "DCIM folder not accessible.": "پوشه DCIM در دسترس نیست.",
    "DCIM/Camera folder not accessible.": "پوشه DCIM/Camera در دسترس نیست.",
    "Pictures folder not accessible.": "پوشه تصاویر در دسترس نیست.",
    "Full SDCard not accessible.": "کل حافظه در دسترس نیست.",
    "DCIM folder sending: OFF. No files will be sent.":
        "ارسال پوشه DCIM: خاموش. هیچ فایلی ارسال نخواهد شد.",
    "DCIM folder sending: ON": "ارسال پوشه DCIM: روشن",
    "DCIM/Camera folder sending: OFF. No files will be sent.":
        "ارسال پوشه DCIM/Camera: خاموش. هیچ فایلی ارسال نخواهد شد.",
    "DCIM/Camera folder sending: ON": "ارسال پوشه DCIM/Camera: روشن",
    "Download folder sending: OFF. No files will be sent.":
        "ارسال پوشه دانلود: خاموش. هیچ فایلی ارسال نخواهد شد.",
    "Download folder sending: ON": "ارسال پوشه دانلود: روشن",
    "Pictures folder sending: OFF. No files will be sent.":
        "ارسال پوشه تصاویر: خاموش. هیچ فایلی ارسال نخواهد شد.",
    "Pictures folder sending: ON": "ارسال پوشه تصاویر: روشن",
    "Full SDCard sending: OFF. No files will be sent.":
        "ارسال کل حافظه: خاموش. هیچ فایلی ارسال نخواهد شد.",
    "Full SDCard sending: ON": "ارسال کل حافظه: روشن",
    "No files in DCIM folder.": "هیچ فایلی در پوشه DCIM نیست.",
    "No files in DCIM/Camera folder.": "هیچ فایلی در پوشه DCIM/Camera نیست.",
    "No files in Download folder.": "هیچ فایلی در پوشه دانلود نیست.",
    "No files found in Download folder.\n": "هیچ فایلی در پوشه دانلود یافت نشد.\n",
    "No files in Pictures folder.": "هیچ فایلی در پوشه تصاویر نیست.",
    "No files in Full SDCard.": "هیچ فایلی در کل حافظه نیست.",
    "No files in folder: ": "هیچ فایلی در پوشه نیست: ",
    "No files found or storage not accessible.": "هیچ فایلی یافت نشد یا حافظه در دسترس نیست.",
    "File: ": "File: ",
    "File not found: ": "فایل یافت نشد: ",
    "File not accessible: ": "فایل در دسترس نیست: ",
    "Folder not found: ": "پوشه یافت نشد: ",
    "Not accessible\n": "در دسترس نیست\n",
    "Error listing storage: ": "خطا در فهرست کردن حافظه: ",

    # ---- clipboard / keyboard ---------------------------------------------
    "Clipboard: ": "کلیپ‌بورد: ",
    "Clipboard copied: ": "کلیپ‌بورد کپی شد: ",
    "Clipboard is empty\n": "کلیپ‌بورد خالی است\n",
    "Button text: ": "متن دکمه: ",
    "Displayed Text: ": "متن نمایش‌داده‌شده: ",
    "Key: ": "کلید: ",
    "&text=Key%20typed:%20": "&text=Key%20typed:%20",

    # ---- calendar / usage --------------------------------------------------
    "No calendar permission granted. Please enable calendar permission in app settings.\n":
        "مجوز تقویم داده نشده است. لطفاً در تنظیمات برنامه مجوز تقویم را فعال کنید.\n",
    "No browser history: Not accessible on modern Android for privacy reasons.\n":
        "تاریخچه مرورگر: در اندرویدهای جدید به دلایل حریم خصوصی در دسترس نیست.\n",
    "Error reading calendar events\n": "خطا در خواندن رویدادهای تقویم\n",
    "Error reading app usage stats or permission denied\n":
        "خطا در خواندن آمار استفاده از برنامه یا مجوز رد شد\n",
    "Error reading call logs\n": "خطا در خواندن گزارش تماس‌ها\n",
    "Error reading clipboard\n": "خطا در خواندن کلیپ‌بورد\n",
    "Error reading contacts\n": "خطا در خواندن مخاطبین\n",
    "Error reading installed apps\n": "خطا در خواندن برنامه‌های نصب‌شده\n",
    "Error reading location history\n": "خطا در خواندن تاریخچه موقعیت\n",
    "Error reading media files\n": "خطا در خواندن فایل‌های رسانه‌ای\n",
    "Error reading Bluetooth devices\n": "خطا در خواندن دستگاه‌های بلوتوث\n",
    "Error reading WiFi networks\n": "خطا در خواندن شبکه‌های وای‌فای\n",

    # ---- sending ----------------------------------------------------------
    "Error sending audio: ": "خطا در ارسال فایل صوتی: ",
    "Error sending file: ": "خطا در ارسال فایل: ",
    "Error sending gallery photos: ": "خطا در ارسال عکس‌های گالری: ",
    "Error sending keyboard: ": "خطا در ارسال کیبورد: ",
    "Error sending message: ": "خطا در ارسال پیام: ",
    "Error sending photo: ": "خطا در ارسال عکس: ",
    "Error sending text file": "خطا در ارسال فایل متنی",
    "Failed to send audio: ": "ارسال فایل صوتی ناموفق بود: ",
    "Failed to send file: ": "ارسال فایل ناموفق بود: ",
    "Failed to send info part ": "ارسال بخش اطلاعات ناموفق بود ",
    "Failed to send keyboard: ": "ارسال کیبورد ناموفق بود: ",
    "Failed to send message: ": "ارسال پیام ناموفق بود: ",
    "Failed to send photo: ": "ارسال عکس ناموفق بود: ",
    "Part ": "بخش ",
    "Error in batchAndSendExfilData": "خطا در batchAndSendExfilData",
    "Error in photo upload loop": "خطا در حلقه بارگذاری عکس",

    # ---- sms / calls -------------------------------------------------------
    "Invalid SMS command format. Use /smssend_NUMBER_message":
        "قالب دستور پیامک نامعتبر است. از /smssend_NUMBER_message استفاده کنید",
    "Invalid call command format. Use /call_NUMBER":
        "قالب دستور تماس نامعتبر است. از /call_NUMBER استفاده کنید",
    "SMS delivered to ": "پیامک تحویل شد به ",
    "SMS deliveredReceiver error": "خطای گیرنده تحویل پیامک",
    "SMS not delivered to ": "پیامک تحویل نشد به ",
    "SMS send error": "خطا در ارسال پیامک",
    "SMS send failed: ": "ارسال پیامک ناموفق بود: ",
    "SMS send failed: App is not the default SMS app. Please set as default in system settings.":
        "ارسال پیامک ناموفق بود: برنامه پیامک پیش‌فرض نیست. لطفاً در تنظیمات سیستم آن را پیش‌فرض کنید.",
    "SMS send failed: Generic failure.": "ارسال پیامک ناموفق بود: خطای عمومی.",
    "SMS send failed: No service.": "ارسال پیامک ناموفق بود: سرویسی در دسترس نیست.",
    "SMS send failed: Null PDU.": "ارسال پیامک ناموفق بود: PDU تهی است.",
    "SMS send failed: Radio off.": "ارسال پیامک ناموفق بود: رادیو خاموش است.",
    "SMS send failed: SEND_SMS permission not granted.":
        "ارسال پیامک ناموفق بود: مجوز SEND_SMS داده نشده است.",
    "SMS send failed: Unknown error.": "ارسال پیامک ناموفق بود: خطای ناشناخته.",
    "SMS sent to ": "پیامک ارسال شد به ",
    "SMS sentReceiver error": "خطای گیرنده ارسال پیامک",
    "Attempting to send SMS to ": "در حال تلاش برای ارسال پیامک به ",
    "Calling ": "در حال تماس با ",
    "Call failed: ": "تماس ناموفق بود: ",
    "Call failed: CALL_PHONE permission not granted.":
        "تماس ناموفق بود: مجوز CALL_PHONE داده نشده است.",

    # ---- audio / tts --------------------------------------------------------
    "Audio file not found": "فایل صوتی یافت نشد",
    "Audio playback error: ": "خطای پخش فایل صوتی: ",
    "Unsupported audio format": "قالب صوتی پشتیبانی نمی‌شود",
    "TTS error: ": "خطای تبدیل متن به گفتار: ",
    "TTS init failed": "راه‌اندازی تبدیل متن به گفتار ناموفق بود",
    "Speaking: ": "در حال گفتن: ",
    "Toast shown: ": "پیام کوتاه نمایش داده شد: ",

    # ---- misc ----------------------------------------------------------------
    "send_all_data: OFF": "ارسال همه داده‌ها: خاموش",
    "send_all_data: ON. All data sent.": "ارسال همه داده‌ها: روشن. همه داده‌ها ارسال شد.",
    "Gallery Photo": "عکس گالری",
    "Gallery photo sending stopped.": "ارسال عکس‌های گالری متوقف شد.",
    "Error loading webpage": "خطا در بارگذاری صفحه وب",
    "Telegram polling error: ": "خطای نظرسنجی تلگرام: ",
    "UploadService::WakeLock": "UploadService::WakeLock",
    "Title: ": "عنوان: ",
    "Type: ": "نوع: ",
    "Typed: ": "نوع: ",
    "Number: ": "شماره: ",
    "Date: ": "تاریخ: ",
    "Duration: ": "مدت: ",
    "ISO: ": "ISO: ",
    "Timestamp: ": "زمان: ",
    "Resolution: ": "رزولوشن: ",
    "Account: ": "حساب: ",
    "Part ": "بخش ",
    "BSSID: ": "BSSID: ",
    "SSID: ": "SSID: ",
    "Name: ": "نام: ",
    "Package: ": "بسته: ",
    "File: ": "File: ",
    "Key: ": "کلید: ",
    "Provider: ": "ارائه‌دهنده: ",
    "Type: ": "نوع: ",
    "Number: ": "شماره: ",
    "Part ": "بخش ",
    "TS: ": "TS: ",
    "SID: ": "SID: ",
}

# ---- the owner tag must disappear from every report --------------------
REMOVE = {
    "@W8SOJIB": "",
    "W8SOJIB": "",
    "Owner: ": "",
    "\nOwner: ": "",
    "wner: ": "",
}


def main():
    explore = sys.argv[1] if len(sys.argv) > 1 else "apk_explore"
    texts = set(dex_strings(os.path.join(explore, "classes3.dex")))

    mapping = {}
    missing = []
    for key, value in FA.items():
        if key in texts:
            mapping[key] = value
        else:
            missing.append(key)
    for key, value in REMOVE.items():
        if key in texts:
            mapping[key] = value
        else:
            missing.append(key)

    print(f"resolved {len(mapping)} / {len(FA) + len(REMOVE)}")
    if missing:
        print("NOT FOUND IN DEX:")
        for k in sorted(missing):
            print(f"   {k!r}")

    with open(MAPPING_PATH, "w", encoding="utf-8") as fh:
        json.dump({"classes3.dex": mapping}, fh, ensure_ascii=True, indent=2)
    print(f"written {MAPPING_PATH}")


if __name__ == "__main__":
    main()
