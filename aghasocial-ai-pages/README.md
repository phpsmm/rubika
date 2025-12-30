# Aghasocial AI Pages

پلاگین مستقل برای سینک سرویس‌ها از ارائه‌دهندگان فعال، بازنویسی محتوای سرویس/دسته با AI، و ساخت صفحات لندینگ Draft با المنتور.

## نصب
1. پوشه `aghasocial-ai-pages` را در مسیر `wp-content/plugins/` قرار دهید.
2. پلاگین را از بخش Plugins فعال کنید.
3. در منوی مدیریت، وارد **Aghasocial AI Pages** شوید و تنظیمات را ذخیره کنید.

## تنظیمات مهم
- **OpenRouter API Key**: کلید API برای متن و تصویر.
- **Text Model / Image Model**: قابل تغییر از پنل.
- **Enable Sync / Rewrite / Generate**: کنترل هر مرحله.
- **Dry Run**: فقط لاگ‌گیری بدون ساخت/آپدیت.
- **Quantity List**: ساخت صفحات مبتنی بر تعداد.
- **Category Include (IDs)**: اگر مقدار بدهید فقط برای این دسته‌ها صفحه ساخته می‌شود.
- **Category Exclude (IDs)**: دسته‌هایی که نباید صفحه ساخته شود.
- **Service Quantity Exclude (IDs)**: سرویس‌هایی که نباید برای تعداد صفحه بسازند.
- **Template Builder Model**: مدل جدا برای ساخت صفحه الگو با AI.
- **Countries**: هر خط به شکل `کشور|صفت`.
- **Elementor Template JSON**: تمپلیت عمومی صفحات.
- **Kando Pack Template JSON**: تمپلیت ویجت `kando-pack` برای صفحات Quantity.

## عملیات دستی در پنل
- **Sync Services + Queue**: دریافت سرویس‌ها و صف‌گذاری Rewrite/Generate.
- **Rewrite 1 Item**: اجرای بازنویسی برای یک آیتم از صف.
- **Generate 1 Page**: ساخت یک صفحه از صف.
- **Update Template from Existing Page**: با وارد کردن Page ID، مقدار `_elementor_data` را به عنوان تمپلیت ذخیره کنید.
- **Build Template Page (AI)**: ساخت صفحه Draft الگو با خروجی AI و جای‌گذارهای `{title}` و دو شورتکد نمونه.

## کرون‌های cPanel (wp-cron خاموش است)
از URLهای نمایش داده شده در پنل برای کرون استفاده کنید:

- Sync: همگام‌سازی سرویس‌ها، سپس صف‌گذاری Rewrite و Generate
- Rewrite: هر بار یک سرویس/دسته
- Generate: هر بار یک صفحه

نمونه دستور cPanel:
```
/usr/bin/curl -s "https://aghasocial.com/wp-content/plugins/aghasocial-ai-pages/cron/sync.php?token=YOUR_TOKEN"
```

## نکات
- صفحات همگی Draft هستند.
- خروجی AI به صورت JSON پارس می‌شود.
- لاگ‌ها در جدول `wp_aghasocial_ai_logs` ثبت می‌شود.

## هماهنگی با Rank Math و SEO
صفحات به شکل Draft ساخته می‌شوند و برای افزودن Schema (FAQPage) از JSON-LD داخل محتوای المنتور/تمپلیت استفاده کنید.

## جداول افزونه
- `wp_aghasocial_ai_queue` صف کارها
- `wp_aghasocial_ai_logs` لاگ درخواست‌ها
- `wp_aghasocial_ai_pages` نگاشت صفحات ساخته‌شده
- `wp_aghasocial_ai_service_meta` متای بازنویسی‌ها
